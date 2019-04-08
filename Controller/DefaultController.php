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
use Novosga\Entity\Local;
use Novosga\Entity\Servico;
use Novosga\Entity\ServicoUnidade;
use Novosga\Entity\ServicoUsuario;
use Novosga\Entity\Usuario;
use Novosga\Http\Envelope;
use Novosga\Service\AtendimentoService;
use Novosga\Event\EventDispatcherInterface;
use Novosga\Service\FilaService;
use Novosga\Service\ServicoService;
use Novosga\Service\UsuarioService;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Translation\TranslatorInterface;

/**
 * DefaultController
 *
 * @author Rogerio Lino <rogeriolino@gmail.com>
 */
class DefaultController extends AbstractController
{
    const DOMAIN = 'NovosgaAttendanceBundle';
    
    /**
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @Route("/", name="novosga_attendance_index", methods={"GET"})
     */
    public function index(
        Request $request,
        AtendimentoService $atendimentoService,
        UsuarioService $usuarioService,
        ServicoService $servicoService,
        TranslatorInterface $translator,
        SecurityService $securityService
    ) {
        $em       = $this->getDoctrine()->getManager();
        $usuario  = $this->getUser();
        $unidade  = $usuario->getLotacao()->getUnidade();
        $repo     = $em->getRepository(Servico::class);
        
        if (!$usuario || !$unidade) {
            return $this->redirectToRoute('home');
        }

        $localId     = $this->getLocalAtendimento($usuarioService, $usuario);
        $numeroLocal = $this->getNumeroLocalAtendimento($usuarioService, $usuario);
        $tipo        = $this->getTipoAtendimento($usuarioService, $usuario);
        $local       = null;

        if ($localId > 0) {
            $local = $em->find(Local::class, $localId);
        }

        $locais = $em
            ->getRepository(Local::class)
            ->findBy([], ['nome' => 'ASC']);
        
        $tiposAtendimento = [
            FilaService::TIPO_TODOS       => $translator->trans('label.all', [], self::DOMAIN),
            FilaService::TIPO_NORMAL      => $translator->trans('label.no_priority', [], self::DOMAIN),
            FilaService::TIPO_PRIORIDADE  => $translator->trans('label.priority', [], self::DOMAIN),
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
            'locais'                => $locais,
            'local'                 => $local,
            'numeroLocal'           => $numeroLocal,
            'tipoAtendimento'       => $tipo,
            'wsSecret'              => $securityService->getWebsocketSecret(),
        ]);
    }

    /**
     *
     * @param Request $request
     * @return Response
     *
     * @Route("/set_local", name="novosga_attendance_setlocal", methods={"POST"})
     */
    public function setLocal(
        Request $request,
        UsuarioService $usuarioService,
        EventDispatcherInterface $dispatcher,
        TranslatorInterface $translator
    ) {
        $envelope = new Envelope();
        
        try {
            $data    = json_decode($request->getContent());
            $localId = (int) ($data->local ?? 0);
            $numero  = (int) ($data->numeroLocal ?? 0);
            $tipo    = ($data->tipoAtendimento ?? FilaService::TIPO_TODOS);
            
            if ($numero <= 0) {
                throw new Exception(
                    $translator->trans('error.place_number', [], self::DOMAIN)
                );
            }

            $tipos = [
                FilaService::TIPO_TODOS, FilaService::TIPO_NORMAL, 
                FilaService::TIPO_PRIORIDADE, FilaService::TIPO_AGENDAMENTO,
            ];
            
            if (!in_array($tipo, $tipos)) {
                throw new Exception(
                    $translator->trans('error.queue_type', [], self::DOMAIN)
                );
            }

            $local = $this
                ->getDoctrine()
                ->getRepository(Local::class)
                ->find($localId);
            
            if (!$local) {
                throw new Exception(
                    $translator->trans('error.place', [], self::DOMAIN)
                );
            }

            $usuario = $this->getUser();
            $unidade = $usuario->getLotacao()->getUnidade();

            $dispatcher->createAndDispatch(
                'novosga.attendance.pre-setlocal',
                [$unidade, $usuario, $local, $numero, $tipo],
                true
            );

            $m1 = $usuarioService->meta($usuario, UsuarioService::ATTR_ATENDIMENTO_LOCAL, $localId);
            $m2 = $usuarioService->meta($usuario, UsuarioService::ATTR_ATENDIMENTO_NUM_LOCAL, $numero);
            $m3 = $usuarioService->meta($usuario, UsuarioService::ATTR_ATENDIMENTO_TIPO, $tipo);

            $dispatcher->createAndDispatch(
                'novosga.attendance.setlocal',
                [$unidade, $usuario, $local, $numero, $tipo],
                true
            );

            $envelope->setData([
                'local'  => $local,
                'numero' => $numero,
                'tipo'   => $tipo,
            ]);
        } catch (Exception $e) {
            $envelope->exception($e);
        }

        return $this->json($envelope);
    }

    /**
     *
     * @param Request $request
     * @return Response
     *
     * @Route("/ajax_update", name="novosga_attendance_ajaxupdate", methods={"GET"})
     */
    public function ajaxUpdate(
        Request $request,
        FilaService $filaService,
        UsuarioService $usuarioService
    ) {
        $envelope    = new Envelope();
        $usuario     = $this->getUser();
        $unidade     = $usuario->getLotacao()->getUnidade();
        $localId     = $this->getLocalAtendimento($usuarioService, $usuario);
        $numeroLocal = $this->getNumeroLocalAtendimento($usuarioService, $usuario);
        $tipo        = $this->getTipoAtendimento($usuarioService, $usuario);
        
        $local = $this
            ->getDoctrine()
            ->getRepository(Local::class)
            ->find($localId);
        
        $servicos     = $usuarioService->servicos($usuario, $unidade);
        $atendimentos = $filaService->filaAtendimento($unidade, $usuario, $servicos, $tipo);
        $total        = count($atendimentos);

        $filas   = [];
        $filas[] = [
            'atendimentos' => $atendimentos,
        ];

        foreach ($servicos as $servico) {
            $atendimentos = $filaService->filaAtendimento($unidade, $usuario, [ $servico ], $tipo);
            $filas[] = [
                'servico'      => $servico,
                'atendimentos' => $atendimentos,
            ];
        }
        
        // fila de atendimento do atendente atual
        $data = [
            'total'    => $total,
            'filas'    => $filas,
            'usuario'  => [
                'local'           => $local,
                'numeroLocal'     => $numeroLocal,
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
     * @Route("/chamar", name="novosga_attendance_chamar", methods={"POST"})
     */
    public function chamar(
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
            $localId     = $this->getLocalAtendimento($usuarioService, $usuario);
            $numeroLocal = $this->getNumeroLocalAtendimento($usuarioService, $usuario);
            $servicos    = $usuarioService->servicos($usuario, $unidade);

            $local = $this
                ->getDoctrine()
                ->getRepository(Local::class)
                ->find($localId);

            do {
                $tipo         = $this->getTipoAtendimento($usuarioService, $usuario);
                $atendimentos = $filaService->filaAtendimento($unidade, $usuario, $servicos, $tipo, 1);
                if (count($atendimentos)) {
                    $proximo = $atendimentos[0];
                    $success = $atendimentoService->chamar($proximo, $usuario, $local, $numeroLocal);
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
     * Chama ou rechama o próximo da fila.
     *
     * @param Novosga\Request $request
     *
     * @Route("/chamar/servico/{id}", name="novosga_attendance_chamar_servico", methods={"POST"})
     */
    public function chamarServico(
        Request $request,
        AtendimentoService $atendimentoService,
        FilaService $filaService,
        UsuarioService $usuarioService,
        TranslatorInterface $translator,
        Servico $servico
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
            $localId     = $this->getLocalAtendimento($usuarioService, $usuario);
            $numeroLocal = $this->getNumeroLocalAtendimento($usuarioService, $usuario);
            $servicoUsuario = $usuarioService->servico($usuario, $servico, $unidade);
            $servicos       = [ $servicoUsuario ];

            if (!$servicoUsuario) {
                throw new \Exception('Serviço não disponível para o atendente atual');
            }

            $local = $this
                ->getDoctrine()
                ->getRepository(Local::class)
                ->find($localId);

            do {
                $tipo         = $this->getTipoAtendimento($usuarioService, $usuario);
                $atendimentos = $filaService->filaAtendimento($unidade, $usuario, $servicos, $tipo, 1);
                if (count($atendimentos)) {
                    $proximo = $atendimentos[0];
                    $success = $atendimentoService->chamar($proximo, $usuario, $local, $numeroLocal);
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
     * @Route("/iniciar", name="novosga_attendance_iniciar", methods={"POST"})
     */
    public function iniciar(
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
     * @Route("/nao_compareceu", name="novosga_attendance_naocompareceu", methods={"POST"})
     */
    public function naoCompareceu(
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
     * @Route("/encerrar", name="novosga_attendance_encerrar", methods={"POST"})
     */
    public function encerrar(
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
        $resolucao  = $data->resolucao;
        $observacao = $data->observacao;
        
        if (empty($servicos)) {
            throw new Exception(
                $translator->trans('error.attendance.no_service', [], self::DOMAIN)
            );
        }
        
        $em = $this->getDoctrine()->getManager();

        $novoUsuario = null;
        $servicoRedirecionado = null;
        
        if ($data->redirecionar) {
            $servicoRedirecionado = $em
                ->getRepository(Servico::class)
                ->find($data->novoServico);
            
            if (isset($data->novoUsuario)) {
                $novoUsuario = $em
                    ->getRepository(Usuario::class)
                    ->find($data->novoServico);
            }
        }
        
        if (in_array($resolucao, [ AtendimentoService::RESOLVIDO, AtendimentoService::PENDENTE ])) {
            $atual->setResolucao($resolucao);
        }
        
        if ($observacao) {
            $atual->setObservacao($observacao);
        }

        $atendimentoService->encerrar($atual, $unidade, $servicos, $servicoRedirecionado, $novoUsuario);

        return $this->json($envelope);
    }

    /**
     * Marca o atendimento como erro de triagem. E gera um novo atendimento para
     * o servico informado.
     *
     * @param Novosga\Request $request
     *
     * @Route("/redirecionar", name="novosga_attendance_redirecionar", methods={"POST"})
     */
    public function redirecionar(
        Request $request,
        AtendimentoService $atendimentoService,
        TranslatorInterface $translator
    ) {
        $envelope = new Envelope();
        $data     = json_decode($request->getContent());

        $usuario     = $this->getUser();
        $unidade     = $usuario->getLotacao()->getUnidade();
        $servico     = (int) $data->servico;
        $novoUsuario = null;
        $atual       = $atendimentoService->atendimentoAndamento($usuario->getId(), $unidade);

        if (!$atual) {
            throw new Exception(
                $translator->trans('error.attendance.not_in_process', [], self::DOMAIN)
            );
        }
        
        if (isset($data->usuario)) {
            $novoUsuario = $this
                ->getDoctrine()
                ->getManager()
                ->getRepository(Usuario::class)
                ->find($data->usuario);
        }

        $redirecionado = $atendimentoService->redirecionar($atual, $unidade, $servico, $novoUsuario);
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
     * @Route("/info_senha/{id}", name="novosga_attendance_infosenha", methods={"GET"})
     */
    public function infoSenha(
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
     * @Route("/consulta_senha", name="novosga_attendance_consultasenha", methods={"GET"})
     */
    public function consultaSenha(Request $request, AtendimentoService $atendimentoService)
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
     * Retorna os usuários que atende o serviço na unidade
     *
     * @param Novosga\Request $request
     *
     * @Route("/usuarios/{id}", name="novosga_attendance_usuarios", methods={"GET"})
     */
    public function usuarios(Request $request, TranslatorInterface $translator, Servico $servico)
    {
        $envelope       = new Envelope();
        $usuario        = $this->getUser();
        $unidade        = $usuario->getLotacao()->getUnidade();
        $em             = $this->getDoctrine()->getManager();
        $servicoUnidade = $em->getRepository(ServicoUnidade::class)->get($unidade, $servico);
        
        if (!$servicoUnidade) {
            throw new Exception(
                $translator->trans('error.service.invalid', [], self::DOMAIN)
            );
        }
        
        $usuarios = $em
            ->getRepository(Usuario::class)
            ->findByServicoUnidade($servicoUnidade);
        
        $envelope->setData($usuarios);
        
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

    private function getLocalAtendimento(UsuarioService $usuarioService, Usuario $usuario)
    {
        $localMeta = $usuarioService->meta($usuario, UsuarioService::ATTR_ATENDIMENTO_LOCAL);
        $numero = $localMeta ? (int) $localMeta->getValue() : null;
        
        return $numero;
    }

    private function getNumeroLocalAtendimento(UsuarioService $usuarioService, Usuario $usuario)
    {
        $numeroLocalMeta = $usuarioService->meta($usuario, UsuarioService::ATTR_ATENDIMENTO_NUM_LOCAL);
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
