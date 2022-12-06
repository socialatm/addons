        function channelrepShowModerateForm( id ) {
                $.post('/channelreputation/' + id , {uid: bParam_uid}, function (data) {
                        $('#channelrepModal').html(data);
                }, null, 'html')
                  .done(function() { $('#channelrepModal').modal('show');
                })
                  .fail(function() { alert("There was an error getting data.");
                })
                ;
        }

        function channelrepPlus() {
                $.post('/channelreputation/', { 
                        form_security_token: $('#channelrepSecurityToken').val(),
                        channelrepId: $('#channelrepId').val(),
                        channelrepPoints: $('#channelrepPoints').val(),
                        channelrepAction: 1,
                        uid: $('#channelrepUid').val()
                        }, function (data) {
                                $('#channelrepModal').modal('hide');
                });
        }
        function channelrepMinus() {
                $.post('/channelreputation/', { 
                        form_security_token: $('#channelrepSecurityToken').val(),
                        channelrepId: $('#channelrepId').val(),
                        channelrepPoints: $('#channelrepPoints').val(),
                        channelrepAction: -1,
                        uid: $('#channelrepUid').val()
                        }, function (data) {
                                $('#channelrepModal').modal('hide');
                });
        }
