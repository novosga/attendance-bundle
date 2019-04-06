/**
 * Novo SGA - Atendimento
 * @author Rogerio Lino <rogeriolino@gmail.com>
 */
var App = App || {};

(function () {
    'use strict'
    
    var defaultTitle = document.title;
    
    var app = new Vue({
        el: '#attendance',
        data: {
            busy: false,
            tiposAtendimento: tiposAtendimento,
            servicosRealizados: [],
            servicosUsuario: JSON.parse(JSON.stringify(servicosUsuario)),
            usuario: {
                local: local,
                numeroLocal: numeroLocal,
                tipoAtendimento: tipoAtendimento
            },
            novoLocal: {
                local: local ? local.id  : null,
                numeroLocal: numeroLocal,
                tipoAtendimento: tipoAtendimento
            },
            atendimento: null,
            atendimentoInfo: null,
            atendimentos: [],
            redirecionarAoEncerrar: false,
            servicoRedirecionar: null,
            search: '',
            searchResult: [],
            usuarios: [],
            novoUsuario: null,
        },
        methods: {
            init: function (atendimento) {
                var self = this;
                
                this.atendimento = atendimento;
                
                if (!App.Notification.allowed()) {
                    $('#notification').show();
                }

                App.Websocket.connect();

                App.Websocket.on('connect', function () {
                    App.Websocket.emit('register user', {
                        secret: wsSecret,
                        user: usuario.id,
                        unity: unidade.id
                    });
                });

                // ajax polling fallback
                App.Websocket.on('reconnect_failed', function () {
                    App.Websocket.connect();
                    console.log('ws timeout, ajax polling fallback');
                    self.update();
                });

                App.Websocket.on('error', function () {
                    console.log('error');
                });

                App.Websocket.on('register ok', function () {
                    console.log('registered!');
                });

                App.Websocket.on('update queue', function () {
                    console.log('update queue: do update!');
                    self.update();
                });

                App.Websocket.on('change user', function () {
                    console.log('change user: do update!');
                    self.update();
                });
                
                if (self.usuario.numeroLocal) {
                    self.update();
                }
            },

            update: function () {
                var self = this;
                App.ajax({
                    url: App.url('/novosga.attendance/ajax_update'),
                    success: function (response) {
                        response.data = response.data || {};
                        var estavaVazio = self.atendimentos.length === 0;
                        self.atendimentos = response.data.atendimentos || [];
                        self.usuario = response.data.usuario || {};
                        
                        // habilitando botao chamar
                        if (self.atendimentos.length > 0) {
                            document.title = "(" + self.atendimentos.length + ") " + defaultTitle;
                            if (estavaVazio) {
                                var audio = document.getElementById("alert");
                                if (audio) {
                                    audio.play();
                                }
                                App.Notification.show('Atendimento', 'Novo atendimento na fila');
                            }
                        }
                    }
                });
            },
            
            infoSenha: function (atendimento) {
                var self = this;
                App.ajax({
                    url: App.url('/novosga.attendance/info_senha/') + atendimento.id,
                    success: function (response) {
                        self.atendimentoInfo = response.data;
                        $('#dialog-senha').modal('show');
                    }
                });
            },
            
            setLocal: function () {
                var self = this;
                
                App.ajax({
                    url: App.url('/novosga.attendance/set_local'),
                    type: 'post',
                    data: self.novoLocal,
                    success: function (response) {
                        Vue.set(self.usuario, 'numeroLocal', response.data.numero.value);
                        self.usuario.local             = response.data.local;
                        self.usuario.numeroLocal       = response.data.numero;
                        self.usuario.tipoAtendimento   = response.data.tipo;
                        self.novoLocal.local           = response.data.local.id;
                        self.novoLocal.numeroLocal     = response.data.numero;
                        self.novoLocal.tipoAtendimento = response.data.tipo;
                        self.atendimentos              = [];
                        self.update();
                        $('#dialog-local').modal('hide');
                    }
                });
            },
            
            chamar: function (e) {
                var self = this;
                self.busy = true;
                
                if (!e.target.disabled) {
                    e.target.disabled = true;

                    App.ajax({
                        url: App.url('/novosga.attendance/chamar'),
                        type: 'post',
                        success: function (response) {
                            self.atendimento = response.data;
                            App.Websocket.emit('call ticket', {
                                unity: unidade.id,
                                service: self.atendimento.servico.id,
                                hash: self.atendimento.hash
                            });
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
                $('#dialog-redirecionar').modal('show');
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
                    $('#dialog-erro-encerrar').modal('show');
                    return;
                }
                
                var data = {
                    redirecionar: false,
                    servicos: servicos.join(','),
                    resolucao: this.atendimento.resolucao,
                    observacao: this.atendimento.observacao
                };
                
                // se foi submetido via modal de redirecionamento
                if (isRedirect) {
                    if (!this.servicoRedirecionar) {
                        $('#dialog-erro-encerrar').modal('show');
                        return;
                    }
                    data.redirecionar = true;
                    data.novoServico = this.servicoRedirecionar;
                    data.novoUsuario = this.novoUsuario;
                } else {
                    if (this.redirecionarAoEncerrar) {
                        this.novoUsuario = null;
                        this.servicoRedirecionar = null;
                        $('#dialog-redirecionar').modal('show');
                        return;
                    }
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
                        success: function () {
                            self.atendimento = null;
                            self.redirecionarAoEncerrar = false;
                            $('.modal').modal('hide');
                        }
                    });
                });
            },
            
            encerrar: function(isRedirect) {
                this.redirecionarAoEncerrar = false;
                this.fazEncerrar(isRedirect);
            },
            
            encerrarRedirecionar: function() {
                this.redirecionarAoEncerrar = true;
                this.fazEncerrar(false);
            },
            
            changeServicoRedirecionar: function () {
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
                                $('.modal').modal('hide');
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
            }
        }
    });
    
    app.init(atendimento);
    
    if (!local) {
        $('#dialog-local').modal('show');
    }
})();
