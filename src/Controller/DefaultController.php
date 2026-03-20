<?php

declare(strict_types=1);

/*
 * This file is part of the Novo SGA project.
 *
 * (c) Rogerio Lino <rogeriolino@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Novosga\AttendanceBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Novosga\AttendanceBundle\Dto\EncerrarAtendimentoDto;
use Novosga\AttendanceBundle\Dto\RedirecionarAtendimentoDto;
use Novosga\AttendanceBundle\Dto\SetLocalDto;
use Novosga\AttendanceBundle\NovosgaAttendanceBundle;
use Novosga\Entity\ServicoUsuarioInterface;
use Novosga\Entity\UnidadeInterface;
use Novosga\Entity\UsuarioInterface;
use Novosga\Event\PreUserSetLocalEvent;
use Novosga\Event\UserSetLocalEvent;
use Novosga\Form\ClienteType;
use Novosga\Http\Envelope;
use Novosga\Repository\AtendimentoRepositoryInterface;
use Novosga\Repository\ClienteRepositoryInterface;
use Novosga\Repository\LocalRepositoryInterface;
use Novosga\Repository\ServicoRepositoryInterface;
use Novosga\Repository\ServicoUnidadeRepositoryInterface;
use Novosga\Repository\UsuarioRepositoryInterface;
use Novosga\Service\ApplicationSettingsServiceInterface;
use Novosga\Service\AtendimentoServiceInterface;
use Novosga\Service\ClienteServiceInterface;
use Novosga\Service\FilaServiceInterface;
use Novosga\Service\ServicoServiceInterface;
use Novosga\Service\UsuarioServiceInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * DefaultController
 *
 * @author Rogerio Lino <rogeriolino@gmail.com>
 */
#[Route("/", name: "novosga_attendance_")]
class DefaultController extends AbstractController
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly ApplicationSettingsServiceInterface $settingsService,
    ) {
    }

    #[Route("/", name: "index", methods: ["GET"])]
    public function index(
        ServicoRepositoryInterface $servicoRepository,
        LocalRepositoryInterface $localRepository,
        AtendimentoServiceInterface $atendimentoService,
        UsuarioServiceInterface $usuarioService,
        ServicoServiceInterface $servicoService,
    ): Response {
        /** @var UsuarioInterface */
        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()?->getUnidade();

        if (!$unidade) {
            return $this->redirectToRoute('home');
        }

        $localId = $this->getLocalAtendimento($usuarioService, $usuario);
        $numeroLocal = $this->getNumeroLocalAtendimento($usuarioService, $usuario);
        $tipo = $this->getTipoAtendimento($usuarioService, $usuario);
        $local = null;

        if ($localId > 0) {
            $local = $localRepository->find($localId);
        }

        $locais = $localRepository->findBy([], ['nome' => 'ASC']);

        $domain = NovosgaAttendanceBundle::getDomain();
        $tiposAtendimento = [
            FilaServiceInterface::TIPO_TODOS       => $this->translator->trans('label.all', [], $domain),
            FilaServiceInterface::TIPO_NORMAL      => $this->translator->trans('label.no_priority', [], $domain),
            FilaServiceInterface::TIPO_PRIORIDADE  => $this->translator->trans('label.priority', [], $domain),
            FilaServiceInterface::TIPO_AGENDAMENTO => $this->translator->trans('label.schedule', [], $domain),
        ];

        $atendimentoAtual = $atendimentoService->getAtendimentoAndamento($usuario, $unidade);
        $servicosUsuario = $usuarioService->getServicosUnidade($usuario, $unidade);

        $servicos = array_map(function (ServicoUsuarioInterface $su) use ($servicoRepository) {
            $subservicos = $servicoRepository->getSubservicos($su->getServico());
            return [
                'servico'     => $su->getServico(),
                'subServicos' => $subservicos,
                'peso'        => $su->getPeso(),
            ];
        }, $servicosUsuario);

        $servicosIndisponiveis = $servicoService->servicosIndisponiveis($unidade, $usuario);
        $settings = $this->settingsService->loadUserBehaviorSettings($usuario);

        return $this->render('@NovosgaAttendance/default/index.html.twig', [
            'time' => time() * 1000,
            'usuario' => $usuario,
            'unidade' => $unidade,
            'atendimento' => $atendimentoAtual,
            'servicos' => $servicos,
            'servicosIndisponiveis' => $servicosIndisponiveis,
            'tiposAtendimento' => $tiposAtendimento,
            'locais' => $locais,
            'local' => $local,
            'numeroLocal' => $numeroLocal,
            'tipoAtendimento' => $tipo,
            'settings' => $settings,
        ]);
    }

    #[Route("/atendimento", name: "atendimento", methods: ["GET"])]
    public function atendimentoAtual(AtendimentoServiceInterface $atendimentoService): Response
    {
        /** @var UsuarioInterface */
        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();
        $atendimentoAtual = $atendimentoService->getAtendimentoAndamento($usuario->getId(), $unidade);

        return $this->json(new Envelope(
            timezone: $unidade->getDateTimeZone(),
            data: $atendimentoAtual,
        ));
    }

    #[Route("/customer/{id}", name: "customer", methods: ["GET", "POST"])]
    public function customerForm(
        Request $request,
        EntityManagerInterface $em,
        AtendimentoServiceInterface $atendimentoService,
        ClienteServiceInterface $clienteService,
        ClienteRepositoryInterface $clienteRepository,
        int $id,
    ): Response {
        $atendimento = $atendimentoService->getById($id);
        if (!$atendimento) {
            throw $this->createNotFoundException();
        }

        if (!$atendimento->getDataInicio()) {
            throw $this->createNotFoundException();
        }

        $response = new Response();

        if (!$atendimento->getCliente()) {
            $novoCliente = null;
            if ($request->isMethod('POST')) {
                $data = $request->get('cliente');
                if (is_array($data) && key_exists('documento', $data)) {
                    $novoCliente = $clienteRepository->findOneBy([
                        'documento' => $data['documento'],
                    ]);
                }
            }

            if (!$novoCliente) {
                $novoCliente = $clienteService->build();
            }

            $atendimento->setCliente($novoCliente);
        }

        $cliente = $atendimento->getCliente();
        $form = $this
            ->createForm(ClienteType::class, $cliente, [
                'csrf_protection' => false,
            ])
            ->handleRequest($request);

        if ($form->isSubmitted()) {
            if (!$form->isValid()) {
                $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
            } else {
                $em->persist($atendimento);
                $em->flush();

                $this->addFlash('success', 'Cliente salvo com sucesso');
            }
        }

        return $this->render('@NovosgaAttendance/default/customer.html.twig', [
            'form' => $form,
        ], $response);
    }

    #[Route("/set_local", name: "setlocal", methods: ["POST"])]
    public function setLocal(
        LocalRepositoryInterface $localRepository,
        UsuarioServiceInterface $usuarioService,
        EventDispatcherInterface $dispatcher,
        #[MapRequestPayload()] SetLocalDto $data,
    ): Response {
        /** @var UsuarioInterface */
        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();
        $envelope = new Envelope(
            timezone: $unidade->getDateTimeZone(),
        );

        try {
            $tipo = ($data->tipoAtendimento ?? FilaServiceInterface::TIPO_TODOS);
            if ($data->numeroLocal <= 0) {
                throw new Exception(
                    $this->translator->trans(
                        'error.place_number',
                        [],
                        NovosgaAttendanceBundle::getDomain(),
                    ),
                );
            }

            $tipos = [
                FilaServiceInterface::TIPO_TODOS,
                FilaServiceInterface::TIPO_NORMAL,
                FilaServiceInterface::TIPO_PRIORIDADE,
                FilaServiceInterface::TIPO_AGENDAMENTO,
            ];

            if (!in_array($tipo, $tipos)) {
                throw new Exception(
                    $this->translator->trans(
                        'error.queue_type',
                        [],
                        NovosgaAttendanceBundle::getDomain(),
                    ),
                );
            }

            $local = $localRepository->find($data->local);
            if (!$local) {
                throw new Exception(
                    $this->translator->trans('error.place', [], NovosgaAttendanceBundle::getDomain())
                );
            }

            $dispatcher->dispatch(new PreUserSetLocalEvent($unidade, $usuario, $local, $data->numeroLocal, $tipo));

            $usuarioService->meta($usuario, UsuarioServiceInterface::ATTR_ATENDIMENTO_LOCAL, $data->local);
            $usuarioService->meta($usuario, UsuarioServiceInterface::ATTR_ATENDIMENTO_NUM_LOCAL, $data->numeroLocal);
            $usuarioService->meta($usuario, UsuarioServiceInterface::ATTR_ATENDIMENTO_TIPO, $tipo);

            $dispatcher->dispatch(new UserSetLocalEvent($unidade, $usuario, $local, $data->numeroLocal, $tipo));

            $envelope->setData([
                'local' => $local,
                'numero' => $data->numeroLocal,
                'tipo' => $tipo,
            ]);
        } catch (Exception $e) {
            $envelope->exception($e);
        }

        return $this->json($envelope);
    }

    #[Route("/ajax_update", name: "ajaxupdate", methods: ["GET"])]
    public function ajaxUpdate(
        LocalRepositoryInterface $localRepository,
        FilaServiceInterface $filaService,
        UsuarioServiceInterface $usuarioService
    ): Response {
        /** @var UsuarioInterface */
        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();
        $localId = $this->getLocalAtendimento($usuarioService, $usuario) ?? 0;
        $numeroLocal = $this->getNumeroLocalAtendimento($usuarioService, $usuario);
        $tipo = $this->getTipoAtendimento($usuarioService, $usuario);

        $local = $localRepository->find($localId);

        $servicos = $usuarioService->getServicosUnidade($usuario, $unidade);
        $atendimentos = $filaService->getFilaAtendimento($unidade, $usuario, $servicos, $tipo);
        $total = count($atendimentos);

        $filas = [];
        $filas[] = [
            'atendimentos' => $atendimentos,
        ];

        foreach ($servicos as $servico) {
            $atendimentos = $filaService->getFilaAtendimento($unidade, $usuario, [ $servico ], $tipo);
            $filas[] = [
                'servico' => $servico,
                'atendimentos' => $atendimentos,
            ];
        }

        // fila de atendimento do atendente atual
        $data = [
            'total' => $total,
            'filas' => $filas,
            'usuario' => [
                'id' => $usuario->getId(),
                'local' => $local,
                'numeroLocal' => $numeroLocal,
                'tipoAtendimento' => $tipo,
            ],
        ];

        return $this->json(new Envelope(
            timezone: $unidade->getDateTimeZone(),
            data: $data,
        ));
    }

    /**
     * Chama ou rechama o próximo da fila.
     */
    #[Route("/chamar", name: "chamar", methods: ["POST"])]
    public function chamar(
        LocalRepositoryInterface $localRepository,
        AtendimentoServiceInterface $atendimentoService,
        UsuarioServiceInterface $usuarioService,
    ): Response {
        /** @var UsuarioInterface */
        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();

        // verifica se ja esta atendendo alguem
        $atendimento = $atendimentoService->getAtendimentoAndamento($usuario->getId(), $unidade);

        // se ja existe um atendimento em andamento (chamando senha novamente)
        if (!$atendimento) {
            $localId = $this->getLocalAtendimento($usuarioService, $usuario);
            $numeroLocal = $this->getNumeroLocalAtendimento($usuarioService, $usuario);
            $servicos = $usuarioService->getServicosUnidade($usuario, $unidade);

            $local = $localRepository->find($localId);
            $tipo = $this->getTipoAtendimento($usuarioService, $usuario);

            $atendimento = $atendimentoService->chamarProximo(
                $unidade,
                $usuario,
                $local,
                $tipo,
                $servicos,
                $numeroLocal,
            );
        }

        if (!$atendimento) {
            throw new Exception(
                $this->translator->trans('error.queue.empty', [], NovosgaAttendanceBundle::getDomain())
            );
        }

        $atendimentoService->chamarSenha($atendimento, $usuario);

        return $this->json(new Envelope(
            timezone: $unidade->getDateTimeZone(),
            data: $atendimento->jsonSerialize(),
        ));
    }

    /**
     * Chama ou rechama o próximo da fila.
     */
    #[Route("/chamar/servico/{id}", name: "chamar_servico", methods: ["POST"])]
    public function chamarServico(
        LocalRepositoryInterface $localRepository,
        ServicoRepositoryInterface $servicoRepository,
        AtendimentoServiceInterface $atendimentoService,
        UsuarioServiceInterface $usuarioService,
        int $id,
    ): Response {
        /** @var UsuarioInterface */
        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();

        $settings = $this->settingsService->loadUserBehaviorSettings($usuario);
        if (!$settings->callTicketByService) {
            throw new Exception('Chamar senha por serviço não é permitido');
        }

        // verifica se ja esta atendendo alguem
        $this->checkAtendimentoEmAndamento($atendimentoService, $usuario, $unidade);

        $servico = $servicoRepository->find($id);
        if (!$servico) {
            throw $this->createNotFoundException();
        }

        $localId = $this->getLocalAtendimento($usuarioService, $usuario);
        $numeroLocal = $this->getNumeroLocalAtendimento($usuarioService, $usuario);
        $servicoUsuario = $usuarioService->getServicoUsuario($usuario, $servico, $unidade);
        $servicos = [ $servicoUsuario ];

        if (!$servicoUsuario) {
            throw new Exception('Serviço não disponível para o atendente atual');
        }

        $local = $localRepository->find($localId);
        $tipo = $this->getTipoAtendimento($usuarioService, $usuario);

        $atendimento = $atendimentoService->chamarProximo(
            $unidade,
            $usuario,
            $local,
            $tipo,
            $servicos,
            $numeroLocal,
        );

        if (!$atendimento) {
            throw new Exception(
                $this->translator->trans('error.queue.empty', [], NovosgaAttendanceBundle::getDomain())
            );
        }

        $atendimentoService->chamarSenha($atendimento, $usuario);

        return $this->json(new Envelope(
            timezone: $unidade->getDateTimeZone(),
            data: $atendimento->jsonSerialize(),
        ));
    }

    /**
     * Chama ou rechama um atendimento mesmo que fora de ordem
     */
    #[Route("/chamar/atendimento/{id}", name: "chamar_atendimento", methods: ["POST"])]
    public function chamarAtendimento(
        LocalRepositoryInterface $localRepository,
        AtendimentoRepositoryInterface $atendimentoRepository,
        AtendimentoServiceInterface $atendimentoService,
        UsuarioServiceInterface $usuarioService,
        int $id,
    ): Response {
        /** @var UsuarioInterface */
        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();

        $settings = $this->settingsService->loadUserBehaviorSettings($usuario);
        if (!$settings->callTicketOutOfOrder) {
            throw new Exception('Chamar senha fora de ordem serviço não é permitido');
        }

        // verifica se ja esta atendendo alguem
        $this->checkAtendimentoEmAndamento($atendimentoService, $usuario, $unidade);

        $atendimento = $atendimentoRepository->find($id);
        if (!$atendimento) {
            throw $this->createNotFoundException();
        }

        $localId = $this->getLocalAtendimento($usuarioService, $usuario);
        $numeroLocal = $this->getNumeroLocalAtendimento($usuarioService, $usuario);
        $local = $localRepository->find($localId);

        $sucesso = $atendimentoService->chamarAtendimento(
            $atendimento,
            $usuario,
            $local,
            $numeroLocal,
        );

        if (!$sucesso) {
            throw new Exception(
                $this->translator->trans('error.queue.empty', [], NovosgaAttendanceBundle::getDomain())
            );
        }

        $atendimentoService->chamarSenha($atendimento, $usuario);

        return $this->json(new Envelope(
            timezone: $unidade->getDateTimeZone(),
            data: $atendimento->jsonSerialize(),
        ));
    }

    /**
     * Inicia o atendimento com o proximo da fila.
     */
    #[Route("/iniciar", name: "iniciar", methods: ["POST"])]
    public function iniciar(AtendimentoServiceInterface $atendimentoService): Response
    {
        /** @var UsuarioInterface */
        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();
        $atual = $atendimentoService->getAtendimentoAndamento($usuario->getId(), $unidade);

        if (!$atual) {
            throw new Exception(
                $this->translator->trans('error.attendance.empty', [], NovosgaAttendanceBundle::getDomain())
            );
        }

        $atendimentoService->iniciarAtendimento($atual, $usuario);

        return $this->json(new Envelope(
            timezone: $unidade->getDateTimeZone(),
            data: $atual->jsonSerialize(),
        ));
    }

    /**
     * Marca o atendimento como nao compareceu.
     */
    #[Route("/nao_compareceu", name: "naocompareceu", methods: ["POST"])]
    public function naoCompareceu(AtendimentoServiceInterface $atendimentoService): Response
    {
        /** @var UsuarioInterface */
        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();
        $atual   = $atendimentoService->getAtendimentoAndamento($usuario->getId(), $unidade);

        if (!$atual) {
            throw new Exception(
                $this->translator->trans('error.attendance.empty', [], NovosgaAttendanceBundle::getDomain())
            );
        }

        $atendimentoService->naoCompareceu($atual, $usuario);

        return $this->json(new Envelope(
            timezone: $unidade->getDateTimeZone(),
            data: $atual->jsonSerialize(),
        ));
    }

    /**
     * Marca o atendimento como encerrado.
     */
    #[Route("/encerrar", name: "encerrar", methods: ["POST"])]
    public function encerrar(
        UsuarioRepositoryInterface $usuarioRepository,
        ServicoRepositoryInterface $servicoRepository,
        AtendimentoServiceInterface $atendimentoService,
        #[MapRequestPayload] EncerrarAtendimentoDto $data,
    ): Response {
        /** @var UsuarioInterface */
        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();
        $atual = $atendimentoService->getAtendimentoAndamento($usuario->getId(), $unidade);

        if (!$atual) {
            throw new Exception(
                $this->translator->trans('error.attendance.not_in_process', [], NovosgaAttendanceBundle::getDomain())
            );
        }

        if (empty($data->servicos)) {
            throw new Exception(
                $this->translator->trans('error.attendance.no_service', [], NovosgaAttendanceBundle::getDomain())
            );
        }

        $novoUsuario = null;
        $servicoRedirecionado = null;

        if ($data->redirecionar) {
            $servicoRedirecionado = $servicoRepository->find($data->novoServico);
            if (isset($data->novoUsuario)) {
                $novoUsuario = $usuarioRepository->find($data->novoUsuario);
            }
        }

        $validResolutionValues = [
            AtendimentoServiceInterface::RESOLVIDO,
            AtendimentoServiceInterface::PENDENTE,
        ];
        if (in_array($data->resolucao, $validResolutionValues)) {
            $atual->setResolucao($data->resolucao);
        }

        if ($data->observacao) {
            $atual->setObservacao($data->observacao);
        }

        $atendimentoService->encerrar($atual, $usuario, $data->servicos, $servicoRedirecionado, $novoUsuario);

        return $this->json(new Envelope(
            timezone: $unidade->getDateTimeZone(),
        ));
    }

    /**
     * Marca o atendimento como erro de triagem. E gera um novo atendimento para
     * o servico informado.
     */
    #[Route("/redirecionar", name: "redirecionar", methods: ["POST"])]
    public function redirecionar(
        AtendimentoServiceInterface $atendimentoService,
        #[MapRequestPayload] RedirecionarAtendimentoDto $data,
    ): Response {
        /** @var UsuarioInterface */
        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();
        $atual = $atendimentoService->getAtendimentoAndamento($usuario->getId(), $unidade);

        if (!$atual) {
            throw new Exception(
                $this->translator->trans('error.attendance.not_in_process', [], NovosgaAttendanceBundle::getDomain())
            );
        }

        $redirecionado = $atendimentoService->redirecionar($atual, $usuario, $data->servico, $data->usuario);
        if (!$redirecionado->getId()) {
            throw new Exception(
                $this->translator->trans(
                    'error.attendance.redirect',
                    [
                        '%atendimento%' => $atual->getId(),
                        '%servico%' => $data->servico,
                    ],
                    NovosgaAttendanceBundle::getDomain()
                )
            );
        }

        return $this->json(new Envelope(
            timezone: $unidade->getDateTimeZone(),
        ));
    }

    #[Route("/info_senha/{id}", name: "infosenha", methods: ["GET"])]
    public function infoSenha(
        AtendimentoServiceInterface $atendimentoService,
        int $id,
    ): Response {
        /** @var UsuarioInterface */
        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();
        $atendimento = $atendimentoService->buscaAtendimento($unidade, $id);

        if (!$atendimento) {
            throw new Exception(
                $this->translator->trans('error.attendance.invalid', [], NovosgaAttendanceBundle::getDomain())
            );
        }

        return $this->json(new Envelope(
            timezone: $unidade->getDateTimeZone(),
            data: $atendimento->jsonSerialize(),
        ));
    }

    #[Route("/consulta_senha", name: "consultasenha", methods: ["GET"])]
    public function consultaSenha(Request $request, AtendimentoServiceInterface $atendimentoService): Response
    {
        /** @var UsuarioInterface */
        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();
        $numero = $request->get('numero', '');
        $atendimentos = $atendimentoService->buscaAtendimentos($unidade, $numero);

        return $this->json(new Envelope(
            timezone: $unidade->getDateTimeZone(),
            data: $atendimentos,
        ));
    }

    /**
     * Retorna os usuários que atende o serviço na unidade
     */
    #[Route("/usuarios/{servicoId}", name: "usuarios", methods: ["GET"])]
    public function usuarios(
        UsuarioRepositoryInterface $usuarioRepository,
        ServicoUnidadeRepositoryInterface $servicoUnidadeRepository,
        int $servicoId
    ): Response {
        /** @var UsuarioInterface */
        $usuario = $this->getUser();
        $unidade = $usuario->getLotacao()->getUnidade();
        $servicoUnidade = $servicoUnidadeRepository->get($unidade, $servicoId);

        if (!$servicoUnidade) {
            throw new Exception(
                $this->translator->trans('error.service.invalid', [], NovosgaAttendanceBundle::getDomain())
            );
        }

        $usuarios = $usuarioRepository->findByServicoUnidade($servicoUnidade);

        return $this->json(new Envelope(
            timezone: $unidade->getDateTimeZone(),
            data: $usuarios,
        ));
    }

    private function getLocalAtendimento(UsuarioServiceInterface $usuarioService, UsuarioInterface $usuario): ?int
    {
        $localMeta = $usuarioService->meta($usuario, UsuarioServiceInterface::ATTR_ATENDIMENTO_LOCAL);
        $numero = $localMeta ? (int) $localMeta->getValue() : null;

        return $numero;
    }

    private function getNumeroLocalAtendimento(UsuarioServiceInterface $usuarioService, UsuarioInterface $usuario): ?int
    {
        $numeroLocalMeta = $usuarioService->meta($usuario, UsuarioServiceInterface::ATTR_ATENDIMENTO_NUM_LOCAL);
        $numero = $numeroLocalMeta ? (int) $numeroLocalMeta->getValue() : null;

        return $numero;
    }

    private function getTipoAtendimento(UsuarioServiceInterface $usuarioService, UsuarioInterface $usuario): ?string
    {
        $tipoAtendimentoMeta = $usuarioService->meta($usuario, UsuarioServiceInterface::ATTR_ATENDIMENTO_TIPO);
        $tipoAtendimento = $tipoAtendimentoMeta ? $tipoAtendimentoMeta->getValue() : FilaServiceInterface::TIPO_TODOS;

        return $tipoAtendimento;
    }

    private function checkAtendimentoEmAndamento(
        AtendimentoServiceInterface $atendimentoService,
        UsuarioInterface $usuario,
        UnidadeInterface $unidade,
    ): void {
        // verifica se ja esta atendendo alguem
        $atendimento = $atendimentoService->getAtendimentoAndamento($usuario, $unidade);
        if ($atendimento) {
            throw new Exception(
                $this->translator->trans('error.attendance.in_process', [], NovosgaAttendanceBundle::getDomain())
            );
        }
    }
}
