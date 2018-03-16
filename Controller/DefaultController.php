<?php

/*
 * This file is part of the Novo SGA project.
 *
 * (c) Rogerio Lino <rogeriolino@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Novosga\AttendanceBundle\Controller;

use App\Service\SecurityService;
use DateTime;
use Exception;
use Novosga\Entity\Atendimento;
use Novosga\Entity\Servico;
use Novosga\Entity\ServicoUsuario;
use Novosga\Entity\Usuario;
use Novosga\Http\Envelope;
use Novosga\Service\AtendimentoService;
use Novosga\Service\EventDispatcher;
use Novosga\Service\FilaService;
use Novosga\Service\ServicoService;
use Novosga\Service\UsuarioService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Translation\TranslatorInterface;

/**
 * DefaultController
 *
 * @author Rogerio Lino <rogeriolino@gmail.com>
 */
class DefaultController extends Controller
{
    const DOMAIN = 'NovosgaAttendanceBundle';
    
    /**
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @Route("/", name="novosga_attendance_index")
     */
    public function indexAction(
        Request $request,
        AtendimentoService $atendimentoService,
        UsuarioService $usuarioService,
        ServicoService $servicoService,
        TranslatorInterface $translator,
        SecurityService $securityService
    ) {
        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();
        $repo    = $this->getDoctrine()->getManager()->getRepository(Servico::class);
        
        if (!$usuario || !$unidade) {
            return $this->redirectToRoute('home');
        }

        $local = $this->getNumeroLocalAtendimento($usuarioService, $usuario);
        $tipo  = $this->getTipoAtendimento($usuarioService, $usuario);
        
        $tiposAtendimento = [
            FilaService::TIPO_TODOS => $translator->trans('label.all', [], self::DOMAIN),
            FilaService::TIPO_NORMAL => $translator->trans('label.no_priority', [], self::DOMAIN),
            FilaService::TIPO_PRIORIDADE => $translator->trans('label.priority', [], self::DOMAIN),
            FilaService::TIPO_AGENDAMENTO => $translator->trans('label.schedule', [], self::DOMAIN),
        ];
        
        $atendimentoAtual = $atendimentoService->atendimentoAndamento($usuario->getId(), $unidade);
        $servicosUsuario  = $usuarioService->servicos($usuario, $unidade);
        
        $servicos = array_map(function (ServicoUsuario $su) use ($repo) {
            $subservicos = $repo->getSubservicos($su->getServico());
            
            return [
                'servico'     => $su->getServico(),
                'subServicos' => $subservicos,
                'peso'        => $su->getPeso(),
            ];
        }, $servicosUsuario);
        
        $servicosIndisponiveis = $servicoService->servicosIndisponiveis($unidade, $usuario);

        return $this->render('@NovosgaAttendance/default/index.html.twig', [
            'time'                  => time() * 1000,
            'usuario'               => $usuario,
            'unidade'               => $unidade,
            'atendimento'           => $atendimentoAtual,
            'servicos'              => $servicos,
            'servicosIndisponiveis' => $servicosIndisponiveis,
            'tiposAtendimento'      => $tiposAtendimento,
            'local'                 => $local,
            'tipoAtendimento'       => $tipo,
            'wsSecret'              => $securityService->getWebsocketSecret(),
        ]);
    }

    /**
     *
     * @param Request $request
     * @return Response
     *
     * @Route("/set_local", name="novosga_attendance_setlocal")
     * @Method("POST")
     */
    public function setLocalAction(Request $request, UsuarioService $usuarioService, EventDispatcher $dispatcher)
    {
        $envelope = new Envelope();
        
        $data   = json_decode($request->getContent());
        $numero = (int) $data->numeroLocal;
        $tipo   = $data->tipoAtendimento;

        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();

        $dispatcher->createAndDispatch('novosga.attendance.pre-setlocal', [$unidade, $usuario, $numero, $tipo], true);

        $m1 = $usuarioService->meta($usuario, UsuarioService::ATTR_ATENDIMENTO_LOCAL, $numero);
        $m2 = $usuarioService->meta($usuario, UsuarioService::ATTR_ATENDIMENTO_TIPO, $tipo);
        
        $dispatcher->createAndDispatch('novosga.attendance.setlocal', [$unidade, $usuario, $numero, $tipo], true);
        
        $envelope->setData([
            'numero' => $m1,
            'tipo'   => $m2,
        ]);

        return $this->json($envelope);
    }

    /**
     *
     * @param Request $request
     * @return Response
     *
     * @Route("/ajax_update", name="novosga_attendance_ajaxupdate")
     */
    public function ajaxUpdateAction(
        Request $request,
        FilaService $filaService,
        UsuarioService $usuarioService
    ) {
        $envelope = new Envelope();
        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();
        $local   = $this->getNumeroLocalAtendimento($usuarioService, $usuario);
        $tipo    = $this->getTipoAtendimento($usuarioService, $usuario);
        
        $servicos     = $usuarioService->servicos($usuario, $unidade);
        $atendimentos = $filaService->filaAtendimento($unidade, $servicos, $tipo);
        
        // fila de atendimento do atendente atual
        $data = [
            'atendimentos' => $atendimentos,
            'usuario'      => [
                'numeroLocal'     => $local,
                'tipoAtendimento' => $tipo,
            ],
        ];

        $envelope->setData($data);

        return $this->json($envelope);
    }

    /**
     * Chama ou rechama o próximo da fila.
     *
     * @param Novosga\Request $request
     *
     * @Route("/chamar", name="novosga_attendance_chamar")
     * @Method("POST")
     */
    public function chamarAction(
        Request $request,
        AtendimentoService $atendimentoService,
        FilaService $filaService,
        UsuarioService $usuarioService,
        TranslatorInterface $translator
    ) {
        $envelope = new Envelope();
        
        $attempts = 5;
        $proximo = null;
        $success = false;
        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();
        
        // verifica se ja esta atendendo alguem
        $atual = $atendimentoService->atendimentoAndamento($usuario->getId(), $unidade);

        // se ja existe um atendimento em andamento (chamando senha novamente)
        if ($atual) {
            $success = true;
            $proximo = $atual;
        } else {
            $local    = $this->getNumeroLocalAtendimento($usuarioService, $usuario);
            $servicos = $usuarioService->servicos($usuario, $unidade);

            do {
                $atendimentos = $filaService->filaAtendimento($unidade, $servicos, 1, 1);
                if (count($atendimentos)) {
                    $proximo = $atendimentos[0];
                    $success = $atendimentoService->chamar($proximo, $usuario, $local);
                    if (!$success) {
                        usleep(100);
                    }
                    --$attempts;
                } else {
                    // nao existe proximo
                    break;
                }
            } while (!$success && $attempts > 0);
        }
        
        // response
        if (!$success) {
            if (!$proximo) {
                throw new Exception(
                    $translator->trans('error.queue.empty', [], self::DOMAIN)
                );
            } else {
                throw new Exception(
                    $translator->trans('error.attendance.in_process', [], self::DOMAIN)
                );
            }
        }

        $atendimentoService->chamarSenha($unidade, $proximo);

        $data = $proximo->jsonSerialize();
        $envelope->setData($data);

        return $this->json($envelope);
    }

    /**
     * Inicia o atendimento com o proximo da fila.
     *
     * @param Novosga\Request $request
     *
     * @Route("/iniciar", name="novosga_attendance_iniciar")
     * @Method("POST")
     */
    public function iniciarAction(
        Request $request,
        AtendimentoService $atendimentoService,
        TranslatorInterface $translator
    ) {
        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();
        $atual   = $atendimentoService->atendimentoAndamento($usuario->getId(), $unidade);

        if (!$atual) {
            throw new Exception(
                $translator->trans('error.attendance.empty', [], self::DOMAIN)
            );
        }
        
        $atendimentoService->iniciarAtendimento($atual, $usuario);

        $data     = $atual->jsonSerialize();
        $envelope = new Envelope();
        $envelope->setData($data);

        return $this->json($envelope);
    }

    /**
     * Marca o atendimento como nao compareceu.
     *
     * @param Novosga\Request $request
     *
     * @Route("/nao_compareceu", name="novosga_attendance_naocompareceu")
     * @Method("POST")
     */
    public function naoCompareceuAction(
        Request $request,
        AtendimentoService $atendimentoService,
        TranslatorInterface $translator
    ) {
        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();
        $atual   = $atendimentoService->atendimentoAndamento($usuario->getId(), $unidade);

        if (!$atual) {
            throw new Exception(
                $translator->trans('error.attendance.empty', [], self::DOMAIN)
            );
        }
        
        $atendimentoService->naoCompareceu($atual, $usuario);

        $data     = $atual->jsonSerialize();
        $envelope = new Envelope();
        $envelope->setData($data);

        return $this->json($envelope);
    }

    /**
     * Marca o atendimento como encerrado.
     *
     * @param Novosga\Request $request
     *
     * @Route("/encerrar", name="novosga_attendance_encerrar")
     * @Method("POST")
     */
    public function encerrarAction(
        Request $request,
        AtendimentoService $atendimentoService,
        TranslatorInterface $translator
    ) {
        $envelope = new Envelope();
        $data     = json_decode($request->getContent());

        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();
        $atual   = $atendimentoService->atendimentoAndamento($usuario->getId(), $unidade);

        if (!$atual) {
            throw new Exception(
                $translator->trans('error.attendance.not_in_process', [], self::DOMAIN)
            );
        }

        $servicos   = explode(',', $data->servicos);
        $observacao = $data->observacao;

        if (empty($servicos)) {
            throw new Exception(
                $translator->trans('error.attendance.no_service', [], self::DOMAIN)
            );
        }

        $servicoRedirecionado = null;
        if ($data->redirecionar) {
            $servicoRedirecionado = $this
                ->getDoctrine()
                ->getManager()
                ->getRepository(Servico::class)
                ->find($data->novoServico);
        }
        
        if ($observacao) {
            $atual->setObservacao($observacao);
        }

        $atendimentoService->encerrar($atual, $unidade, $usuario, $servicos, $servicoRedirecionado);

        return $this->json($envelope);
    }

    /**
     * Marca o atendimento como erro de triagem. E gera um novo atendimento para
     * o servico informado.
     *
     * @param Novosga\Request $request
     *
     * @Route("/redirecionar", name="novosga_attendance_redirecionar")
     * @Method("POST")
     */
    public function redirecionarAction(
        Request $request,
        AtendimentoService $atendimentoService,
        TranslatorInterface $translator
    ) {
        $envelope = new Envelope();
        $data     = json_decode($request->getContent());

        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();
        $servico = (int) $data->servico;
        $atual   = $atendimentoService->atendimentoAndamento($usuario->getId(), $unidade);

        if (!$atual) {
            throw new Exception(
                $translator->trans('error.attendance.not_in_process', [], self::DOMAIN)
            );
        }

        $redirecionado = $atendimentoService->redirecionar($atual, $usuario, $unidade, $servico);
        if (!$redirecionado->getId()) {
            throw new Exception(
                $translator->trans(
                    'error.attendance.redirect',
                    [
                        '%atendimento%' => $atual->getId(),
                        '%servico%'     => $servico,
                    ],
                    self::DOMAIN
                )
            );
        }

        return $this->json($envelope);
    }

    /**
     *
     * @param Request $request
     * @return Response
     *
     * @Route("/info_senha/{id}", name="novosga_attendance_infosenha")
     */
    public function infoSenhaAction(
        Request $request,
        AtendimentoService $atendimentoService,
        TranslatorInterface $translator,
        $id
    ) {
        $envelope    = new Envelope();
        $usuario     = $this->getUser();
        $unidade     = $usuario->getLotacao()->getUnidade();
        $atendimento = $atendimentoService->buscaAtendimento($unidade, $id);

        if (!$atendimento) {
            throw new Exception(
                $translator->trans('error.attendance.invalid', [], self::DOMAIN)
            );
        }

        $data = $atendimento->jsonSerialize();
        $envelope->setData($data);

        return $this->json($envelope);
    }

    /**
     * Busca os atendimentos a partir do número da senha.
     *
     * @param Novosga\Request $request
     *
     * @Route("/consulta_senha", name="novosga_attendance_consultasenha")
     */
    public function consultaSenhaAction(Request $request, AtendimentoService $atendimentoService)
    {
        $envelope     = new Envelope();
        $usuario      = $this->getUser();
        $unidade      = $usuario->getLotacao()->getUnidade();
        $numero       = $request->get('numero');
        $atendimentos = $atendimentoService->buscaAtendimentos($unidade, $numero);
        $envelope->setData($atendimentos);
        
        return $this->json($envelope);
    }

    /**
     * @param Atendimento $atendimento
     * @param mixed       $statusAtual (array[int] | int)
     * @param int         $novoStatus
     * @param string      $campoData
     *
     * @return bool
     */
    private function mudaStatusAtendimento(Atendimento $atendimento, $statusAtual, $novoStatus, $campoData = null)
    {
        $em = $this->getDoctrine()->getManager();
        
        $cond = '';
        if ($campoData !== null) {
            $cond = ", e.$campoData = :data";
        }
        if (!is_array($statusAtual)) {
            $statusAtual = [$statusAtual];
        }
        
        $data = (new DateTime())->format('Y-m-d H:i:s');
        
        // atualizando atendimento
        $query = $em->createQuery("
            UPDATE
                Novosga\Entity\Atendimento e
            SET
                e.status = :novoStatus $cond
            WHERE
                e.id = :id AND
                e.status IN (:statusAtual)
        ");
        if ($campoData !== null) {
            $query->setParameter('data', $data);
        }
        $query->setParameter('novoStatus', $novoStatus);
        $query->setParameter('id', $atendimento->getId());
        $query->setParameter('statusAtual', $statusAtual);

        return $query->execute() > 0;
    }

    private function getNumeroLocalAtendimento(UsuarioService $usuarioService, Usuario $usuario)
    {
        $numeroLocalMeta = $usuarioService->meta($usuario, UsuarioService::ATTR_ATENDIMENTO_LOCAL);
        $numero = $numeroLocalMeta ? (int) $numeroLocalMeta->getValue() : null;
        
        return $numero;
    }
     
    private function getTipoAtendimento(UsuarioService $usuarioService, Usuario $usuario)
    {
        $tipoAtendimentoMeta = $usuarioService->meta($usuario, UsuarioService::ATTR_ATENDIMENTO_TIPO);
        $tipoAtendimento = $tipoAtendimentoMeta ? $tipoAtendimentoMeta->getValue() : FilaService::TIPO_TODOS;
        
        return $tipoAtendimento;
    }
}
