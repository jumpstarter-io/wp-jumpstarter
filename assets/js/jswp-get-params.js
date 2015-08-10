
window.jsGetParams = null;

window.jsGetParam = function(key) {
    if (window.jsGetParams !== null) {
        return window.jsGetParams[key] || null;
    }
    var vars = {};
    var queryStrArr = window.location.href.slice(window.location.href.indexOf("?") + 1).split("&");
    for (var i = 0; i < queryStrArr.length; i++) {
        var arr = queryStrArr[i].split("=");
        vars[decodeURIComponent(arr[0])] = (arr.length > 1)? decodeURIComponent(arr[1]): true;
    }
    window.jsGetParams = vars;
    return window.jsGetParam(key);
};