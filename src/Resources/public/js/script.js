/**
 * Novo SGA - Atendimento
 * @author Rogerio Lino <rogeriolino@gmail.com>
 */
var App = App || {};

(function () {
    'use strict'
    
    var defaultTitle = document.title;

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
        },
        methods: {
            update() {
                var self = this;
                App.ajax({
                    url: App.url('/novosga.attendance/ajax_update'),
                    success: function (response) {
                        response.data = response.data || {};
                        var estavaVazio = self.total === 0;
                        self.filas = response.data.filas || [];
                        self.usuario = response.data.usuario || {};
                        self.total = response.data.total;
                        
                        // habilitando botao chamar
                        if (self.total > 0) {
                            document.title = "(" + self.total + ") " + defaultTitle;
                            if (estavaVazio) {
                                try {
                                    document.getElementById('alert').play()
                                } catch (e) {
                                    console.error(e)
                                }
                                App.Notification.show('Atendimento', 'Novo atendimento na fila');
                            }
                        }
                    }
                });
            },
            
            infoSenha(atendimento) {
                var self = this;
                App.ajax({
                    url: App.url('/novosga.attendance/info_senha/') + atendimento.id,
                    success: function (response) {
                        self.atendimentoInfo = response.data;
                        new bootstrap.Modal('#dialog-senha').show();
                    }
                });
            },

            setLocal() {
                var self = this;
                
                App.ajax({
                    url: App.url('/novosga.attendance/set_local'),
                    type: 'post',
                    data: self.novoLocal,
                    success: function (response) {
                        new bootstrap.Modal(app.$refs.localModal).hide();
                        Vue.set(self.usuario, 'numeroLocal', response.data.numero.value);
                        self.usuario.local             = response.data.local;
                        self.usuario.numeroLocal       = response.data.numero;
                        self.usuario.tipoAtendimento   = response.data.tipo;
                        self.novoLocal.local           = response.data.local.id;
                        self.novoLocal.numeroLocal     = response.data.numero;
                        self.novoLocal.tipoAtendimento = response.data.tipo;
                        self.filas                     = [];
                        self.total                     = 0;
                        self.update();
                    }
                });
            },
            
            chamar(e) {
                var self = this;
                self.busy = true;
                
                if (!e.target.disabled) {
                    e.target.disabled = true;

                    App.ajax({
                        url: App.url('/novosga.attendance/chamar'),
                        type: 'post',
                        success: function (response) {
                            self.atendimento = response.data;
                        },
                        complete: function () {
                            setTimeout(function () {
                                self.busy = false;
                                e.target.disabled = false;
                            }, 3 * 1000);
                        }
                    });
                }
            },
            
            chamarServico: function (servico) {
                var self = this;
                self.busy = true;

                App.ajax({
                    url: App.url('/novosga.attendance/chamar/servico/' + servico.id),
                    type: 'post',
                    success: function (response) {
                        self.atendimento = response.data;
                    },
                    complete: function () {
                        setTimeout(function () {
                            self.busy = false;
                        }, 3 * 1000);
                    }
                });
            },
            
            iniciar: function () {
                var self = this;
                App.ajax({
                    url: App.url('/novosga.attendance/iniciar'),
                    type: 'post',
                    success: function (response) {
                        self.atendimento = response.data;
                    }
                });
            },
            
            naoCompareceu: function () {
                var self = this;
                
                swal({
                    title: alertTitle,
                    text: alertNaoCompareceu,
                    type: "warning",
                    buttons: [
                        labelNao,
                        labelSim
                    ],
                    //dangerMode: true,
                })
                .then(function (ok) {
                    if (!ok) {
                        return;
                    }
                    
                    App.ajax({
                        url: App.url('/novosga.attendance/nao_compareceu'),
                        type: 'post',
                        success: function () {
                            self.atendimento = null;
                        }
                    });
                });
            },
            
            erroTriagem: function () {
                this.novoUsuario = null;
                this.servicoRedirecionar = null;
                this.redirecionarModal.show();
            },
            
            preparaEncerrar: function () {
                this.servicosRealizados = [];
                this.servicosUsuario = JSON.parse(JSON.stringify(servicosUsuario));
                if (this.servicosUsuario.length === 1) {
                    var su = this.servicosUsuario[0];
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
            
            fazEncerrar: function (isRedirect) {
                var self = this;
                
                var servicos = this.servicosRealizados.map(function (servico) {
                    return servico.id;
                });
                
                if (servicos.length === 0) {
                    swal({
                        type: "error",
                        title: modalErrorTitle,
                        text: modalErrorText,
                    });
                    return;
                }
                
                var data = {
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
                    //dangerMode: true,
                })
                .then(function (ok) {
                    if (!ok) {
                        return;
                    }
                    
                    App.ajax({
                        url: App.url('/novosga.attendance/encerrar'),
                        type: 'post',
                        data: data,
                        success() {
                            self.atendimento = null;
                            self.redirecionarAoEncerrar = false;
                            App.Modal.closeAll();
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
                var servico = this.servicoRedirecionar,
                    self = this;
            
                this.usuarios = [];
            
                if (servico > 0) {
                    App.ajax({
                        url: App.url(`/novosga.attendance/usuarios/${servico}`),
                        success: function (response) {
                            self.usuarios = response.data;
                        }
                    });
                }
            },
            
            redirecionar: function () {
                var servico = this.servicoRedirecionar,
                    self = this;
            
                if (servico > 0) {
                    swal({
                        title: alertTitle,
                        text: alertRedirecionar,
                        type: "warning",
                        buttons: [
                            labelNao,
                            labelSim
                        ],
                        //dangerMode: true,
                    })
                    .then(function (ok) {
                        if (!ok) {
                            return;
                        }

                        App.ajax({
                            url: App.url('/novosga.attendance/redirecionar'),
                            type: 'post',
                            data: {
                                servico: servico,
                                usuario: self.novoUsuario
                            },
                            success: function () {
                                self.atendimento = null;
                                App.Modal.closeAll();
                            }
                        });
                    });
                }
            },
            
            addServicoRealizado: function (servico) {
                this.servicosRealizados.push(servico);
                servico.disabled = true;
            },
            
            removeServicoRealizado: function (servico) {
                this.servicosRealizados.splice(this.servicosRealizados.indexOf(servico), 1);
                servico.disabled = false;
            },
            
            consultar: function () {
                var self = this;
                App.ajax({
                    url: App.url('/novosga.attendance/consulta_senha'),
                    data: {
                        numero: self.search
                    },
                    success: function (response) {
                        self.searchResult = response.data;
                    }
                });
            },

            getItemFilaStyle(atendimento) {
                let styles = []
                if (atendimento.prioridade.cor) {
                    styles.push(`color: ${atendimento.prioridade.cor}`)
                }
                return styles.join(';')
            },

            async loadCustomer() {
                const body = this.$refs.customerModal.querySelector('.modal-body')
                body.innerHTML = '';
                const url = `${this.$el.dataset.baseUrl}customer/${this.atendimento.id}`
                const resp = await fetch(url)
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
        }
    });

    if (!local) {
        new bootstrap.Modal(app.$refs.localModal).show();
    }
})();
