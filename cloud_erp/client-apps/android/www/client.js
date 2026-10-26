(function () {
  var fallbackUrl = 'https://ultitech.io/login.php';

  // Remote-server mode normally opens server.url directly; this runs only for offline fallback assets.
  if (
    window.location.protocol === 'file:' ||
    window.location.pathname.indexOf('/index.html') !== -1
  ) {
    window.location.replace(fallbackUrl);
  }
})();
