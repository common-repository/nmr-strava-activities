(function ($) {
    $(document).ready(function () {
        $('#btn_activate').on("click", function (ev) {
            $.ajax({
                    type: "PUT",
                    url: `${stravanmrapi.get_url}?action=nmr-strava-setup-callback`,
                    data: {
                        'init_stravanmr_nonce': `${$('#init_stravanmr_nonce').val()}`,
                        'nmr_strava_settings[clientId]': $('#nmr_strava_settings\\[clientId\\]').val(),
                        'nmr_strava_settings[clientSecret]': $('#nmr_strava_settings\\[clientSecret\\]').val(),
                        'nmr_strava_settings[redirectUri]': $('#nmr_strava_settings\\[redirectUri\\]').val(),
                        'nmr_strava_settings[webhook_callback_url]': $('#nmr_strava_settings\\[webhook_callback_url\\]').val(),
                        'nmr_strava_settings[verify_token]': $('#nmr_strava_settings\\[verify_token\\]').val(),
                    }
                })
                .done(function (data) {
                    alert("Strava webhook registered");
                    location.reload();
                })
                .fail(function (xhr, desc, err) {
                    var detail = err;
                    if (xhr && xhr.responseJSON) {
                        detail = xhr.responseJSON;
                    }
                    alert(detail);
                });
        });
        $('#btn_deactivate').on("click", function (ev) {
            $.ajax({
                    type: "DELETE",
                    url: `${stravanmrapi.get_url}?action=nmr-strava-setup-callback`,
                    data: {
                        'init_stravanmr_nonce': `${$('#init_stravanmr_nonce').val()}`,
                    }
                })
                .done(function (data) {
                    alert("Strava webhook removed");
                    location.reload();
                })
                .fail(function (xhr, desc, err) {
                    var detail = err;
                    if (xhr && xhr.responseJSON) {
                        detail = xhr.responseJSON;
                    }
                    alert(detail);
                });
        });
    });
})(jQuery);