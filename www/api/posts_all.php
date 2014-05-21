<?php
// Implements the del.icio.us API request for all a user's posts.

// Arguments: (copied from: https://github.com/SciDevs/delicious-api/blob/master/api/posts.md#v1postsallhashes on 2014-05-18)
/*
- `&tag_separator=comma` (optional) - (Recommended) Returns tags separated by a comma, instead of a space character. A space separator is currently used by default to avoid breaking existing clients - these default may change in future API revisions.
- `&tag={TAG}` (optional) — Filter by this tag.
- `&start={xx}` (optional) — Start returning posts this many results into the set.
- `&results={xx}` (optional) — Return up to this many results. By default, up to 1000 bookmarks are returned. (no limits in scuttles)
- `&fromdt={CCYY-MM-DDThh:mm:ssZ}` (optional) — Filter for posts on this date or later.
- `&todt={CCYY-MM-DDThh:mm:ssZ}` (optional) — Filter for posts on this date or earlier.
- `&meta=yes` (optional) — Include change detection signatures on each item in a ‘meta’ attribute. Clients wishing to maintain a synchronized local store of bookmarks should retain the value of this attribute - its value will change when any significant field of the bookmark changes.
*/

// Scuttle behavior:
// - returns privacy status of each bookmark.
// - There is no upper limit for the 'results' argument

// fail, will be called, if somethink "goes wrong" to copy delicious behaviour
function fail() {
    echo '<result code="something went wrong"/>';
    exit();
}

// no_bookmarks: indicate, that there are no bookmarks using these filters
function no_bookmarks() {
    echo '<result code="no bookmarks"/>';
    exit();
}

// Force HTTP authentication first!
$httpContentType = 'text/xml';
require_once 'httpauth.inc.php';

// xml header
echo '<?xml version="1.0" encoding="UTF-8" ?'.">\r\n";

/* Service creation: only useful services are created */
$bookmarkservice =SemanticScuttle_Service_Factory::get('Bookmark');

// special case - hashes: only return url hash and meta (change indicator)
if ( isset($_REQUEST['hashes']) ) {
    // get all bookmarks
    $bookmarks = $bookmarkservice->getBookmarks(0, NULL, $userservice->getCurrentUserId());

    // output:
    // Set up the XML file and output all the posts.
    echo "<posts>\r\n";
    foreach($bookmarks['bookmarks'] as $row) {
        $url = md5($row['bAddress']);
        //create a meta, which changes, when the bookmark changes in the form of a md5 hash (as delicious does it).
        $meta = md5($row['bModified']);

        echo "\t<post meta=\"". $meta .'" url="'. $url ."\"/>\r\n";
    }

    echo '</posts>';
    exit();
}

// Check to see if a tag was specified.
if (isset($_REQUEST['tag']) && (trim($_REQUEST['tag']) != ''))
    $tag = trim($_REQUEST['tag']);
else
    $tag = NULL;

// 'tag separator' option
if (isset($_REQUEST['tag_separator']) && (trim($_REQUEST['tag_separator']) == 'comma')) 
    $tag_separator = ',';
else
    $tag_separator = ' ';

// 'start' option
if (isset($_REQUEST['start']) && (intval($_REQUEST['start']) > 0))
    $start = intval($_REQUEST['start']);
else
    $start = 0; //default in delicious api

// 'results' option
// upper limit of delicious api is 100000. There is no upper limit here. TODO: implement upper limit?
if (isset($_REQUEST['results'])) {
    if( $_REQUEST['results'] < 0 )
        fail(); //like delicious
    elseif( $_REQUEST['results'] == 0 )
        no_bookmarks();
    else
        $results = intval($_REQUEST['results']);
} else {
    $results = 1000; //default, as in delicious api
}

// 'fromdt' option: filter result by date
if (isset($_REQUEST['fromdt'])) {
    $date = new DateTime( $_REQUEST['fromdt'] , new DateTimeZone('UTC')); //adjust to UTC
    $fromdt = date('Y-m-d\TH:i:s\Z', $date->getTimestamp());
} else {
    $fromdt = NULL;
}

// 'todt' option: filter result by date
if (isset($_REQUEST['todt'])) {
    $date = new DateTime( $_REQUEST['todt'] , new DateTimeZone('UTC')); //adjust to UTC
    $todt = date('Y-m-d\TH:i:s\Z', $date->getTimestamp());
} else {
    $todt = NULL;
}

// 'meta' option: get meta (change indicator)
if (isset($_REQUEST['meta']) && (trim($_REQUEST['meta']) == 'yes'))
    $meta = true;
else
    $meta = false;

// Get the posts relevant to the passed-in variables.
$bookmarks = $bookmarkservice->getBookmarks($start, $results, $userservice->getCurrentUserId(), $tag, NULL, NULL, NULL, $fromdt, $todt);

// check for empty result
if (count($bookmarks['bookmarks'])==0) {
    no_bookmarks();
}

// Set up the XML file and output all the posts.
echo '<posts update="'. gmdate('Y-m-d\TH:i:s\Z') .'" user="'. htmlspecialchars($currentUser->getUsername()) .'" total="'. count($bookmarks['bookmarks']) .'" '. (is_null($tag) ? '' : ' tag="'. htmlspecialchars($tag) .'"') .">\r\n";

foreach($bookmarks['bookmarks'] as $row) {
    if (is_null($row['bDescription']) || (trim($row['bDescription']) == ''))
        $description = '';
    else
        $description = 'extended="'. filter($row['bDescription'], 'xml') .'" ';

    $taglist = '';
    if (count($row['tags']) > 0) {
        foreach($row['tags'] as $tag)
            $taglist .= convertTag($tag) . $tag_separator;
        $taglist = substr($taglist, 0, -1);
    } else {
        $taglist = 'system:unfiled';
    }

    if( $meta ) {
        $meta_print = ' meta="'. md5($row['bModified']) .'" ';
    } else {
        $meta_print = '';
    }

    echo "\t<post href=\"". filter($row['bAddress'], 'xml') .'" description="'. filter($row['bTitle'], 'xml') .'" '. $description .'hash="'. md5($row['bAddress']) .'" tag="'. filter($taglist, 'xml') .'" time="'. gmdate('Y-m-d\TH:i:s\Z', strtotime($row['bDatetime'])) . '"  '. $meta_print .' status="'. filter($row['bStatus'], 'xml') ."\" />\r\n";
}

echo '</posts>';
?>
