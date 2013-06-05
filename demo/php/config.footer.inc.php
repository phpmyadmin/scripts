<script type="text/javascript" src="/common-script.js"></script>
<div class="notice">
<a href="/" target="_top">phpMyAdmin demo server</a><?php
if (file_exists('./revision-info.php')) {
    include('./revision-info.php');
    echo ', currently running <a target="_top" href="http://wiki.phpmyadmin.net/pma/Git">Git</a> revision ';
    echo '<a target="_top" href="' . $repobase . $fullrevision . '">';
    echo $revision;
    echo '</a> from ';
    echo '<a target="_top" href="' . $repobranchbase . $branch . '">' . $branch . '</a> branch';
    if (!empty($reponame)) {
        echo ' in <a target="_top" href="https://github.com/' . $reponame . '/">' . $reponame . '</a> fork';
    }
} else {
    echo ', Git information missing';
}
?>.
</div>
<div style="clear:both;"></div>
<!-- Piwik -->
<script type="text/javascript">
  var _paq = _paq || [];
  _paq.push(["trackPageView"]);
  _paq.push(["enableLinkTracking"]);

  (function() {
    var u=(("https:" == document.location.protocol) ? "https" : "http") + "://stats.cihar.com/";
    _paq.push(["setTrackerUrl", u+"piwik.php"]);
    _paq.push(["setSiteId", "3"]);
    var d=document, g=d.createElement("script"), s=d.getElementsByTagName("script")[0]; g.type="text/javascript";
    g.defer=true; g.async=true; g.src=u+"piwik.js"; s.parentNode.insertBefore(g,s);
  })();
</script>
<!-- End Piwik Code -->
