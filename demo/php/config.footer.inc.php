<script type="text/javascript" src="/common-script.js"></script>
<div class="notice">
<a href="/" target="_top">phpMyAdmin demo server</a><?php
if (file_exists('./revision-info.php')) {
    include('./revision-info.php');
    echo ', currently running <a href="http://wiki.phpmyadmin.net/pma/Git">Git</a> revision ';
    echo '<a href="' . $repobase . $fullrevision . '">';
    echo $revision;
    echo '</a> from ';
    echo '<a href="' . $repobranchbase . $branch . '">' . $branch . '</a> branch';
    if (!empty($reponame)) {
        echo ' in <a href="https://github.com/' . $reponame . '/">' . $reponame . '</a> fork';
    }
} else {
    echo ', Git information missing';
}
?>.
</div>
<div style="clear:both;"></div>
<!-- Piwik -->
<script type="text/javascript">
var pkBaseURL = (("https:" == document.location.protocol) ? "https://stats.cihar.com/" : "http://stats.cihar.com/");
</script>
<script type="text/javascript" src="http://stats.cihar.com/piwik.js"></script>
<script type="text/javascript">
try {
    var piwikTracker = Piwik.getTracker(pkBaseURL + "piwik.php", 3);
    piwikTracker.trackPageView();
    piwikTracker.enableLinkTracking();
} catch( err ) {}
</script><noscript><p><img src="http://stats.cihar.com/piwik.php?idsite=3" style="border:0" alt="" /></p></noscript>
<!-- End Piwik Tracking Code -->
