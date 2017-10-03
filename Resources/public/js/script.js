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
            unidade: unidade,
            tiposAtendimento: tiposAtendimento,
            servicosRealizados: [],
            servicosUsuario: servicosUsuario,
            usuario: {
                numeroLocal: local,
                tipoAtendimento: tipoAtendimento
            },
            novoLocal: {
                numeroLocal: local,
                tipoAtendimento: tipoAtendimento
            },
            atendimento: null,
            atendimentoInfo: null,
            atendimentos: [],
            redirecionarAoEncerrar: false,
            servicoRedirecionar: null,
            search: '',
            searchResult: [],
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
                    console.log('connected!');
                    App.Websocket.emit('register user', {
                        unidade: self.unidade.id
                    });
                });

                App.Websocket.on('disconnect', function () {
                    console.log('disconnected!');
                });

                App.Websocket.on('error', function () {
                    console.log('error');
                });

                App.Websocket.on('register ok', function () {
                    console.log('registered!');
                });

                App.Websocket.on('update queue', function () {
                    console.log('do update!');
                    self.update();
                });
                
                self.update();
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
                                document.getElementById("alert").play();
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
                App.ajax({
                    url: App.url('/novosga.attendance/set_local'),
                    type: 'post',
                    data: this.novoLocal,
                    success: function (response) {
                        window.location.reload();
                    }
                });
            },
            
            chamar: function () {
                var self = this;
                App.ajax({
                    url: App.url('/novosga.attendance/chamar'),
                    type: 'post',
                    success: function (response) {
                        self.atendimento = response.data;
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
                App.ajax({
                    url: App.url('/novosga.attendance/nao_compareceu'),
                    type: 'post',
                    success: function () {
                        self.atendimento = null;
                    }
                });
            },
            
            erroTriagem: function () {
                $('#dialog-redirecionar').modal('show');
            },
            
            preparaEncerrar: function () {
                this.servicosRealizados = [];
                this.atendimento.status = 'encerrando';
            },
            
            encerrar: function (isRedirect) {
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
                    servicos: servicos.join(',')
                };
                
                // se foi submetido via modal de redirecionamento
                if (isRedirect) {
                    if (!this.servicoRedirecionar) {
                        $('#dialog-erro-encerrar').modal('show');
                        return;
                    }
                    data.redirecionar = true;
                    data.novoServico = this.servicoRedirecionar;
                } else {
                    if (this.redirecionarAoEncerrar) {
                        $('#dialog-redirecionar').modal('show');
                        return;
                    }
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
            },
            
            redirecionar: function () {
                var servico = this.servicoRedirecionar,
                    self = this;
            
                if (servico > 0 && window.confirm(MARCAR_ERRO_TRIAGEM)) {
                    App.ajax({
                        url: App.url('/novosga.attendance/redirecionar'),
                        type: 'post',
                        data: {
                            servico: servico
                        },
                        success: function () {
                            self.atendimento = null;
                            $('.modal').modal('hide');
                        }
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
    
    if (!local) {
        $('#dialog-local').modal('show');
    } else {
        app.init(atendimento);
    }
    
})();