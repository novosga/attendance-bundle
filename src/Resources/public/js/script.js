/**
 * Novo SGA - Atendimento
 * @author Rogerio Lino <rogeriolino@gmail.com>
 */
(function () {
    'use strict'
    
    const defaultTitle = document.title;

    const app = new Vue({
        el: '#attendance',
        data: {
            busy: false,
            filas: [],
            total: 0,
            tiposAtendimento: tiposAtendimento,
            servicosRealizados: [],
            servicosUsuario: JSON.parse(JSON.stringify(servicosUsuario)),
            usuario: {
                id: (usuario.id),
                local: local,
                numeroLocal: numeroLocal,
                tipoAtendimento: tipoAtendimento
            },
            novoLocal: {
                local: local ? local.id  : null,
                numeroLocal: numeroLocal,
                tipoAtendimento: tipoAtendimento
            },
            atendimento: (atendimento || null),
            unidade: (unidade || {}),
            atendimentoInfo: null,
            atendimentos: [],
            redirecionarAoEncerrar: false,
            servicoRedirecionar: null,
            search: '',
            searchResult: [],
            usuarios: [],
            novoUsuario: null,
            redirecionarModal: null,
            customerModal: null,
            senhaModal: null,
            localModal: null,
        },
        methods: {
            update() {
                App.ajax({
                    url: App.url('/novosga.attendance/ajax_update'),
                    success: (response) => {
                        response.data = response.data || {};
                        const estavaVazio = this.total === 0;
                        this.filas = response.data.filas || [];
                        this.usuario = response.data.usuario || {};
                        this.total = response.data.total;

                        // habilitando botao chamar
                        if (this.total > 0) {
                            document.title = "(" + this.total + ") " + defaultTitle;
                            if (estavaVazio) {
                                try {
                                    document.getElementById('alert').play()
                                } catch (e) {
                                    console.error(e)
                                }
                                App.Notification.show('Atendimento', 'Novo atendimento na fila');
                            }
                        } else {
                            document.title = defaultTitle;
                        }
                    }
                });
            },
            
            infoSenha(atendimento) {
                App.ajax({
                    url: App.url(`/novosga.attendance/info_senha/${atendimento.id}`),
                    success: (response) => {
                        this.atendimentoInfo = response.data;
                        this.senhaModal.show();
                    }
                });
            },

            setLocal() {
                App.ajax({
                    url: App.url('/novosga.attendance/set_local'),
                    type: 'post',
                    data: this.novoLocal,
                    success: (response) => {
                        this.localModal.hide();
                        Vue.set(this.usuario, 'numeroLocal', response.data.numero.value);
                        this.usuario.local = response.data.local;
                        this.usuario.numeroLocal = response.data.numero;
                        this.usuario.tipoAtendimento = response.data.tipo;
                        this.novoLocal.local = response.data.local.id;
                        this.novoLocal.numeroLocal = response.data.numero;
                        this.novoLocal.tipoAtendimento = response.data.tipo;
                        this.filas = [];
                        this.total = 0;
                        this.update();
                    }
                });
            },
            
            chamar(e) {
                this.busy = true;
                if (!e.target.disabled) {
                    e.target.disabled = true;
                    App.ajax({
                        url: App.url('/novosga.attendance/chamar'),
                        type: 'post',
                        success: (response) => {
                            this.atendimento = response.data;
                            this.update();
                        },
                        complete: () => {
                            setTimeout(() => {
                                this.busy = false;
                                e.target.disabled = false;
                            }, 3 * 1000);
                        }
                    });
                }
            },
            
            chamarServico(servico) {
                this.busy = true;
                App.ajax({
                    url: App.url(`/novosga.attendance/chamar/servico/${servico.id}`),
                    type: 'post',
                    success: (response) => {
                        this.atendimento = response.data;
                    },
                    complete: () => {
                        setTimeout(() => this.busy = false, 3 * 1000);
                    }
                });
            },
            
            chamarAtendimento(atendimento) {
                this.busy = true;
                App.ajax({
                    url: App.url(`/novosga.attendance/chamar/atendimento/${atendimento.id}`),
                    type: 'post',
                    success: (response) => {
                        this.atendimento = response.data;
                    },
                    complete: () => {
                        setTimeout(() => this.busy = false, 3 * 1000);
                    }
                });
            },
            
            iniciar() {
                App.ajax({
                    url: App.url('/novosga.attendance/iniciar'),
                    type: 'post',
                    success: (response) => {
                        this.atendimento = response.data;
                    }
                });
            },
            
            naoCompareceu() {
                swal({
                    title: alertTitle,
                    text: alertNaoCompareceu,
                    type: "warning",
                    buttons: [
                        labelNao,
                        labelSim
                    ],
                })
                .then((ok) => {
                    if (!ok) {
                        return;
                    }
                    App.ajax({
                        url: App.url('/novosga.attendance/nao_compareceu'),
                        type: 'post',
                        success: () => {
                            this.atendimento = null;
                            this.update();
                        }
                    });
                });
            },
            
            erroTriagem() {
                this.novoUsuario = null;
                this.servicoRedirecionar = null;
                this.redirecionarModal.show();
            },
            
            preparaEncerrar() {
                this.servicosRealizados = [];
                this.servicosUsuario = JSON.parse(JSON.stringify(servicosUsuario));
                if (this.servicosUsuario.length === 1) {
                    const su = this.servicosUsuario[0];
                    if (su.subServicos.length === 0) {
                        this.addServicoRealizado(su.servico);
                    } else if (su.subServicos.length === 1) {
                        this.addServicoRealizado(su.subServicos[0]);
                    }
                }
                this.atendimento.status = 'encerrando';
            },
            
            encerrarVoltar: function () {
                this.atendimento.status = 'iniciado';
            },
            
            fazEncerrar(isRedirect) {
                const servicos = this.servicosRealizados.map((servico) => servico.id);
                if (servicos.length === 0) {
                    swal({
                        type: "error",
                        title: modalErrorTitle,
                        text: modalErrorText,
                    });
                    return;
                }
                
                const data = {
                    servicos,
                    redirecionar: false,
                    resolucao: this.atendimento.resolucao,
                    observacao: this.atendimento.observacao
                };

                // se foi submetido via modal de redirecionamento
                if (isRedirect) {
                    if (!this.servicoRedirecionar) {
                        swal({
                            type: "error",
                            title: modalErrorTitle,
                            text: modalErrorText,
                        });
                        return;
                    }
                    data.redirecionar = true;
                    data.novoServico = this.servicoRedirecionar;
                    data.novoUsuario = this.novoUsuario;
                } else if (this.redirecionarAoEncerrar) {
                    this.novoUsuario = null;
                    this.servicoRedirecionar = null;
                    this.redirecionarModal.show();
                    return;
                }

                swal({
                    title: alertTitle,
                    text: alertEncerrar,
                    type: "warning",
                    buttons: [
                        labelNao,
                        labelSim
                    ],
                })
                .then((ok) => {
                    if (!ok) {
                        return;
                    }
                    
                    App.ajax({
                        url: App.url('/novosga.attendance/encerrar'),
                        type: 'post',
                        data: data,
                        success: () => {
                            this.atendimento = null;
                            this.redirecionarAoEncerrar = false;
                            App.Modal.closeAll();
                            this.update();
                        }
                    });
                });
            },
            
            encerrar(isRedirect) {
                this.redirecionarAoEncerrar = false;
                this.fazEncerrar(isRedirect);
            },
            
            encerrarRedirecionar() {
                this.redirecionarAoEncerrar = true;
                this.fazEncerrar(false);
            },
            
            changeServicoRedirecionar() {
                if (this.servicoRedirecionar > 0) {
                    this.usuarios = [];
                    App.ajax({
                        url: App.url(`/novosga.attendance/usuarios/${this.servicoRedirecionar}`),
                        success: (response) => {
                            this.usuarios = response.data;
                        }
                    });
                }
            },
            
            redirecionar() {
                if (this.servicoRedirecionar > 0) {
                    swal({
                        title: alertTitle,
                        text: alertRedirecionar,
                        type: "warning",
                        buttons: [
                            labelNao,
                            labelSim
                        ],
                    })
                    .then((ok) => {
                        if (!ok) {
                            return;
                        }
                        App.ajax({
                            url: App.url('/novosga.attendance/redirecionar'),
                            type: 'post',
                            data: {
                                servico: this.servicoRedirecionar,
                                usuario: this.novoUsuario
                            },
                            success: () => {
                                this.atendimento = null;
                                App.Modal.closeAll();
                                this.update();
                            }
                        });
                    });
                }
            },
            
            addServicoRealizado(servico) {
                this.servicosRealizados.push(servico);
                servico.disabled = true;
            },
            
            removeServicoRealizado(servico) {
                this.servicosRealizados.splice(this.servicosRealizados.indexOf(servico), 1);
                servico.disabled = false;
            },
            
            consultar() {
                App.ajax({
                    url: App.url('/novosga.attendance/consulta_senha'),
                    data: {
                        numero: this.search,
                    },
                    success: (response) => {
                        this.searchResult = response.data;
                    }
                });
            },

            getItemFilaStyle(atendimento) {
                let styles = [];
                if (atendimento.prioridade.cor) {
                    styles.push(`color: ${atendimento.prioridade.cor}`);
                }
                return styles.join(';')
            },

            async loadCustomer() {
                const body = this.$refs.customerModal.querySelector('.modal-body')
                body.innerHTML = '';
                const url = `${this.$el.dataset.baseUrl}customer/${this.atendimento.id}`;
                const resp = await fetch(url);
                if (resp.ok) {
                    body.innerHTML = await resp.text();
                }
            },

            recarregar() {
                App.ajax({
                    url: App.url('/novosga.attendance/atendimento'),
                    success: (response) => {
                        this.atendimento = response.data;
                    }
                })
            },

            saveCustomer() {
                const url = `${this.$el.dataset.baseUrl}customer/${this.atendimento.id}`;
                const body = this.$refs.customerModal.querySelector('.modal-body');
                const form = this.$refs.customerModal.querySelector('form');
                const data = new FormData(form);
                const submitButton = form.querySelector('button[type=submit]');
                submitButton.disabled = true;
                fetch(url, {
                    method: 'post',
                    body: data,
                }).then(async (resp) => {
                    this.recarregar();
                    body.innerHTML = await resp.text();
                    submitButton.disabled = false;
                }).catch(() => {
                    alert('Erro ao salvar cliente');
                    submitButton.disabled = false;
                });
            }
        },
        mounted() {
            this.redirecionarModal = new bootstrap.Modal(this.$refs.redirecionarModal);
            this.customerModal = new bootstrap.Modal(this.$refs.customerModal);
            this.senhaModal = new bootstrap.Modal(this.$refs.senhaModal);
            this.localModal = new bootstrap.Modal(this.$refs.localModal);

            this.$refs.customerModal.addEventListener('shown.bs.modal', (event) => {
                this.loadCustomer();
            });

            if (!App.Notification.allowed()) {
                document.getElementById('notification').style.display = 'inline';
            }

            if (this.usuario.numeroLocal) {
                this.update();
            }

            App.SSE.connect([
                `/unidades/${this.unidade.id}/fila`,
                `/usuarios/${this.usuario.id}/fila`,
            ]);

            App.SSE.onmessage = (e, data) => {
                this.update();
            };

            // ajax polling fallback
            App.SSE.ondisconnect = () => {
                this.update();
            };
            
            this.update();

            if (!local) {
                this.localModal.show();
            }
        }
    });
})();
