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

use Exception;
use Novosga\Http\Envelope;
use Novosga\Entity\Atendimento;
use Novosga\Entity\Usuario;
use Novosga\Entity\ServicoUsuario;
use Novosga\Service\AtendimentoService;
use Novosga\Service\Dispatcher;
use Novosga\Service\FilaService;
use Novosga\Service\UsuarioService;
use Novosga\Service\ServicoService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;

/**
 * DefaultController
 *
 * @author Rogerio Lino <rogeriolino@gmail.com>
 */
class DefaultController extends Controller
{
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
            ServicoService $servicoService
    ) {
        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();
        
        if (!$usuario || !$unidade) {
            return $this->redirectToRoute('home');
        }

        $local = $this->getNumeroLocalAtendimento($usuario);
        $tipo = $this->getTipoAtendimento($usuario);

        $tiposAtendimento = [
            1 => _('Todos'),
            2 => _('Convencional'),
            3 => _('Prioridade'),
        ];
        
        $atendimentoAtual = $atendimentoService->atendimentoAndamento($usuario->getId());
        
        $servicosUsuario = $usuarioService->servicos($usuario, $unidade);
        
        $servicos = array_map(function (ServicoUsuario $su) {
            return [
                'servico'     => $su->getServico(),
                'subServicos' => $su->getServico()->getSubServicos()->toArray(),
                'peso'        => $su->getPeso(),
            ];
        }, $servicosUsuario);
        
        $servicosIndisponiveis = $servicoService->servicosIndisponiveis($unidade, $usuario);

        return $this->render('@NovosgaAttendance/default/index.html.twig', [
            'time' => time() * 1000,
            'unidade' => $unidade,
            'atendimento' => $atendimentoAtual,
            'servicos' => $servicos,
            'servicosIndisponiveis' => $servicosIndisponiveis,
            'tiposAtendimento' => $tiposAtendimento,
            'local' => $local,
            'tipoAtendimento' => $tipo
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
    public function setLocalAction(Request $request, UsuarioService $usuarioService, Dispatcher $dispatcher)
    {
        $envelope = new Envelope();
        
        $data = json_decode($request->getContent());
        $numero = (int) $data->numeroLocal;
        $tipo   = (int) $data->tipoAtendimento;

        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();

        $dispatcher->dispatch('sga.atendimento.pre-setlocal', [$unidade, $usuario, $numero, $tipo]);

        $usuarioService->meta($usuario, UsuarioService::ATTR_ATENDIMENTO_LOCAL, $numero);
        $usuarioService->meta($usuario, UsuarioService::ATTR_ATENDIMENTO_TIPO, $tipo);

        $dispatcher->dispatch('sga.atendimento.setlocal', [$unidade, $usuario, $numero, $tipo]);

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
        
        $servicos     = $usuarioService->servicos($usuario, $unidade);
        $atendimentos = $filaService->filaAtendimento($unidade, $servicos);

        // fila de atendimento do atendente atual
        $data = [
            'atendimentos' => $atendimentos,
            'usuario'      => [
                'numeroLocal'     => $this->getNumeroLocalAtendimento($usuario),
                'tipoAtendimento' => $this->getTipoAtendimento($usuario),
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
            UsuarioService $usuarioService
    ) {
        $envelope = new Envelope();
        
        $attempts = 5;
        $proximo = null;
        $success = false;
        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();
        
        if (!$usuario) {
            throw new Exception(_('Nenhum usuário na sessão'));
        }

        // verifica se ja esta atendendo alguem
        $atual = $atendimentoService->atendimentoAndamento($usuario->getId());

        // se ja existe um atendimento em andamento (chamando senha novamente)
        if ($atual) {
            $success = true;
            $proximo = $atual;
        } else {
            $local = $this->getNumeroLocalAtendimento($usuario);
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
                throw new Exception(_('Fila vazia'));
            } else {
                throw new Exception(_('Já existe um atendimento em andamento'));
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
    public function iniciarAction(Request $request, AtendimentoService $atendimentoService)
    {
        return $this->mudaStatusAtualResponse($request, $atendimentoService, AtendimentoService::CHAMADO_PELA_MESA, AtendimentoService::ATENDIMENTO_INICIADO, 'dataInicio');
    }

    /**
     * Marca o atendimento como nao compareceu.
     *
     * @param Novosga\Request $request
     *
     * @Route("/nao_compareceu", name="novosga_attendance_naocompareceu")
     * @Method("POST")
     */
    public function naoCompareceuAction(Request $request, AtendimentoService $atendimentoService)
    {
        return $this->mudaStatusAtualResponse($request, $atendimentoService, AtendimentoService::CHAMADO_PELA_MESA, AtendimentoService::NAO_COMPARECEU, 'dataFim');
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
            AtendimentoService $atendimentoService
    ) {
        $envelope = new Envelope();
        
        $data = json_decode($request->getContent());

        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();
        $atual = $atendimentoService->atendimentoAndamento($usuario->getId());

        if (!$atual) {
            throw new Exception(_('Nenhum atendimento em andamento'));
        }

        $servicos = explode(',', $data->servicos);

        if (empty($servicos)) {
            throw new Exception(_('Nenhum serviço selecionado'));
        }

        $servicoRedirecionado = null;
        if ($data->redirecionar) {
            $servicoRedirecionado = $data->novoServico;
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
            AtendimentoService $atendimentoService
    ) {
        $envelope = new Envelope();
        
        $data = json_decode($request->getContent());

        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();
        $servico = (int) $data->servico;
        $atual = $atendimentoService->atendimentoAndamento($usuario->getId());

        if (!$atual) {
            throw new Exception(_('Nenhum atendimento em andamento'));
        }

        $redirecionado = $atendimentoService->redirecionar($atual, $usuario, $unidade, $servico);
        if (!$redirecionado->getId()) {
            throw new Exception(sprintf(_('Erro ao redirecionar atendimento %s para o serviço %s'), $atual->getId(), $servico));
        }

        $success = $this->mudaStatusAtendimento($atual, [AtendimentoService::ATENDIMENTO_INICIADO, AtendimentoService::ATENDIMENTO_ENCERRADO], AtendimentoService::ERRO_TRIAGEM, 'dataFim');
        if (!$success) {
            throw new Exception(sprintf(_('Erro ao mudar status do atendimento %s para encerrado'), $atual->getId()));
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
            $id
    ) {
        $envelope = new Envelope();
        
        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();
        $atendimento = $atendimentoService->buscaAtendimento($unidade, $id);

        if (!$atendimento) {
            throw new Exception(_('Atendimento inválido'));
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
    public function consultaSenhaAction(
            Request $request,
            AtendimentoService $atendimentoService
    ) {
        $envelope = new Envelope();
        
        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();
        $numero = $request->get('numero');
        $atendimentos = $atendimentoService->buscaAtendimentos($unidade, $numero);
        $envelope->setData($atendimentos);
        
        return $this->json($envelope);
    }

    /**
     * Muda o status do atendimento atual.
     *
     * @param mixed  $statusAtual (array[int] | int)
     * @param int    $novoStatus
     * @param string $campoData
     *
     * @return Response
     */
    private function mudaStatusAtualResponse(Request $request, AtendimentoService $atendimentoService, $statusAtual, $novoStatus, $campoData)
    {
        $usuario = $this->getUser();
        if (!$usuario) {
            return $this->redirectToRoute('home');
        }
        
        $envelope = new Envelope();
        
        $atual = $atendimentoService->atendimentoAndamento($usuario->getId());

        if (!$atual) {
            throw new Exception(_('Nenhum atendimento disponível'));
        }

        // atualizando atendimento
        $success = $this->mudaStatusAtendimento($atual, $statusAtual, $novoStatus, $campoData);

        if (!$success) {
            throw new Exception(_('Erro desconhecido'));
        }

        $atual->setStatus($novoStatus);

        $data = $atual->jsonSerialize();
        $envelope->setData($data);

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
        
        $data = (new \DateTime())->format('Y-m-d H:i:s');
        
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

    private function getNumeroLocalAtendimento(Usuario $usuario)
    {
        $em = $this->getDoctrine()->getManager();
        $service = new UsuarioService($em);
        
        $numeroLocalMeta = $service->meta($usuario, 'atendimento.local');
        $numero = $numeroLocalMeta ? (int) $numeroLocalMeta->getValue() : '';
        
        return $numero;
    }
     
    private function getTipoAtendimento(Usuario $usuario)
    {
        $em = $this->getDoctrine()->getManager();
        $service = new UsuarioService($em);
        
        $tipoAtendimentoMeta = $service->meta($usuario, 'atendimento.tipo');
        $tipoAtendimento = $tipoAtendimentoMeta ? (int) $tipoAtendimentoMeta->getValue() : 1;
        
        return $tipoAtendimento;
    }
}
