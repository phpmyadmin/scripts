<?php if (substr(PMA_VERSION, 0, 3) != '4.2' && substr(PMA_VERSION, 0, 3) != '4.1') { ?>
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
<?php } ?>
<script type="text/javascript">

  var _gaq = _gaq || [];
  _gaq.push(['_setAccount', 'UA-2718724-5']);
  _gaq.push(['_trackPageview']);

  (function() {
    var ga = document.createElement('script'); ga.type = 'text/javascript'; ga.async = true;
    ga.src = ('https:' == document.location.protocol ? 'https://ssl' : 'http://www') + '.google-analytics.com/ga.js';
    var s = document.getElementsByTagName('script')[0]; s.parentNode.insertBefore(ga, s);
  })();

</script>
