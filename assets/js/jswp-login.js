
jQuery(document).ready(function() {
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
});
