<?php

/**
 * Executed on cron to auto trigger build when new upstream tags are pushed.
 */

const TAG_FILE = 'tags.newest';

// Tags are already sorted properly by github so first one should be newest. Eventually this will
// need to paginate and will probably be best achomplished using one of the API clients.
// For some reason github blocks file_get_contents() calls even when User-Agent is set so use curl.
$tags = json_decode(`curl --silent "https://api.github.com/repos/drupal/drupal/tags?page=1&per_page=100"`, true);
$tag_newest = false;
foreach ($tags as $tag) {
  if (isset($tag['name']) && strpos($tag['name'], '7') === 0) { // Starts with '7'.
    $tag_newest = $tag['name'];
    break;
  }
}
if ($tag_newest === false) {
  echo "Github is being mean...exiting.\n";
  exit;
}
echo "Newest tag: $tag_newest\n";

if (!file_exists(TAG_FILE) || $tag_newest != trim(file_get_contents(TAG_FILE))) {
  echo "Triggering build...\n";
  passthru('git pull origin 7.x');
  file_put_contents(TAG_FILE, $tag_newest . "\n");
  passthru('git add ' . TAG_FILE . ' && git commit -m "New tag pushed upstream, trigger build."');
  passthru('git push origin 7.x');
}
else {
  echo "No changes\n";
}
