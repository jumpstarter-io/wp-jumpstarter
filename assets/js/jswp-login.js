
jQuery(document).ready(function() {
    jQuery("#loginform").append(jQuery("#js-login").detach());
    jQuery("#js-login").show();
    var loginHref = jQuery("#js-login-reflected").attr("href");
    var insecureDomain = jsGetParam("insecure-domain");
    if (loginHref !== "#" && !insecureDomain)
        return;
    jQuery("#js-login-reflected").attr("href", "").addClass("disabled").attr("title", "Login through Jumpstarter is disabled on insecure domains");
    jQuery("#js-insecure-domain").detach().insertBefore("#loginform");
    jQuery("#js-login-reflected").click(function(ev) {
        ev.preventDefault();
        jQuery("#js-insecure-domain").show();
        return false;
    });
    if (insecureDomain) {
        jQuery("#js-insecure-domain").show();
    }

    // Setup handlers for getting a reset password email.
    var doResetRequest = function(cb) {
        var data = {
        "action": "js_send_reset_email"
        };
        // reset_ajax is populated by the WordPress engine.
        jQuery.post(reset_ajax.url, data, function(response) {
            cb && cb(JSON.parse(response));
        });
    };

    var $ = jQuery;
    var sALearnMore = "#js-auth-learn-more",
        sDLearnMore = "#js-insecure-domain-learn-more";
    $("#js-auth-learn-more").on("click", function(ev) {
        ev.preventDefault();
        if ($(sDLearnMore).is(":visible")) {
            $(sDLearnMore).slideUp(function() {
                $(sALearnMore).html("Learn more &raquo;");
            });
        } else {
            $(sDLearnMore).slideDown(function() {
                $(sALearnMore).html("Show less &laquo;");
            });
        }
        return false;
    });
    var sASendReq = "#js-insecure-domain-btn-send",
        sISpinner = "#js-insecure-domain-spinner";
    $(sASendReq).on("click", function(ev) {
        ev.preventDefault();
        if ($(sASendReq).attr("disabled") === "disabled") {
            return;
        }
        $(sASendReq).attr("disabled", "disabled");
        $(sISpinner).show();
        $(".js-err").hide();
        doResetRequest(function(rsp) {
            $(sISpinner).hide();
            $(sASendReq).removeAttr("disabled");
            if (rsp["status"].toLowerCase() === "ok") {
                $("#js-insecure-reset-ok").show();
            } else if (rsp["status"].toLowerCase() === "fail") {
                $("#js-insecure-reset-err-gen p").text(rsp["err_msg"]);
                $("#js-insecure-reset-err-gen").show();
            } else if (rsp["status"].toLowerCase() === "fail-too-often") {
                $("#js-insecure-reset-err-too-often").show();
                $(sASendReq).attr("disabled", "disabled");
                setTimeout(function() {
                    $(sASendReq).removeAttr("disabled");
                }, 180 * 1000);
            }
       });
       return false;
    });
});
