<?php
/**
 * Implements the del.icio.us API request for a user's posts,
 * optionally filtered by tag and/or date.
 * Note that when using a date to select the posts returned, del.icio.us
 * uses GMT dates -- so we do too.
 *
 * del.icio.us behavior:
 * - includes an empty tag attribute on the root element when it hasn't been specified
 *
 * Scuttle behavior:
 * - Uses today, instead of the last bookmarked date, if no date is specified
 * - returns privacy status of each bookmark.
 *
 * SemanticScuttle - your social bookmark manager.
 *
 * PHP version 5.
 *
 * @category Bookmarking
 * @package  SemanticScuttle
 * @author   Benjamin Huynh-Kim-Bang <mensonge@users.sourceforge.net>
 * @author   Christian Weiske <cweiske@cweiske.de>
 * @author   Eric Dane <ericdane@users.sourceforge.net>
 * @license  GPL http://www.gnu.org/licenses/gpl.html
 * @link     http://sourceforge.net/projects/semanticscuttle
 */

// Force HTTP authentication first!
$httpContentType = 'text/xml';
require_once 'httpauth.inc.php';

/* Service creation: only useful services are created */
$bookmarkservice = SemanticScuttle_Service_Factory::get('Bookmark');

//// 'meta' argument
$includeMeta = (isset($_REQUEST['meta']) && (trim($_REQUEST['meta']) == 'yes'));

//// 'tag_separator' argument
if (isset($_REQUEST['tag_separator']) && (trim($_REQUEST['tag_separator']) == 'comma')) {
    $tag_separator = ',';
} else {
    $tag_separator = ' ';
}

//// 'url' argument
if (isset($_REQUEST['url']) && (trim($_REQUEST['url']) != '')) {
    $_REQUEST['hashes'] = md5($_REQUEST['url']);
}

//// 'hashes' argument
if (isset($_REQUEST['hashes']) && (trim($_REQUEST['hashes']) != '')) {
    $hashes = explode(' ', trim($_REQUEST['hashes']) );
    // directly get the bookmarks for these hashes
    //TODO: getBookmarks can't handle multiple hashes
    $bookmarks = $bookmarkservice->getBookmarks(
        0, null, $userservice->getCurrentUserId(), null,
        null, null, null, null, null, $hashes
    );
} else {

    //// 'tag' argument
    // Check to see if a tag was specified.
    if (isset($_REQUEST['tag']) && (trim($_REQUEST['tag']) != '')) {
        // convert spaces back to '+' and explode
        $tag = str_replace(' ', '+', trim($_REQUEST['tag']));
        $tag = explode('+', $tag);
    } else {
        $tag = null;
    }

    //// 'dt' argument
    // Check to see if a date was specified; the format should be YYYY-MM-DD in GMT/UTC
    if (isset($_REQUEST['dt']) && (trim($_REQUEST['dt']) != '')) {
        $dtstart = trim($_REQUEST['dt']);
    } else {
        $dtstart = gmdate('Y-m-d') . ' 00:00:00'; //Default: Today midnight (UTC)
    }
    //adjust from UTC to server time
    $date = new DateTime( $dtstart , new DateTimeZone('UTC'));
    $dtstart = date('Y-m-d H:i:s', $date->getTimestamp());
    $dtstart_day = date('Y-m-d', $date->getTimestamp());

    $dtend = date('Y-m-d H:i:s', strtotime($dtstart .'+1 day'));

    //

    // Get the posts relevant to the passed-in variables.
    $bookmarks = $bookmarkservice->getBookmarks(
        0, null, $userservice->getCurrentUserId(), $tag,
        null, null, null, $dtstart, $dtend
    );

}


// Set up the XML file and output all the tags.
echo '<?xml version="1.0" encoding="UTF-8" ?'.">\r\n";
echo '<posts'. (is_null($dtstart_day) ? '' : ' dt="'. $dtstart_day .'"') .' tags="'. (is_null($tag) ? '' : filter(implode($tag, $tag_separator), 'xml')) .'" user="'. filter($currentUser->getUsername(), 'xml') ."\">\r\n";

foreach ($bookmarks['bookmarks'] as $row) {
    if (is_null($row['bDescription']) || (trim($row['bDescription']) == '')) {
        $description = '';
    } else {
        $description = 'extended="'. filter($row['bDescription'], 'xml') .'" ';
    }

    $taglist = '';
    if (count($row['tags']) > 0) {
        foreach ($row['tags'] as $tag) {
            $taglist .= convertTag($tag) . $tag_separator;
        }
        $taglist = substr($taglist, 0, -1);
    } else {
        $taglist = 'system:unfiled';
    }

    echo "\t<post href=\"". filter($row['bAddress'], 'xml') .'" description="'. filter($row['bTitle'], 'xml') .'" '. $description .'hash="'. $row['bHash'] .'" '. ($includeMeta?'meta="'. md5($row['bModified']) .'"':'') .' others="'. $bookmarkservice->countOthers($row['bAddress']) .'" tag="'. filter($taglist, 'xml') .'" time="'. gmdate('Y-m-d\TH:i:s\Z', strtotime($row['bDatetime'])) . '" private="'. ($row['bStatus']==2?'yes':'no') .'" shared="'. ($row['bStatus']==2?'no':'yes') .'" status="'. filter($row['bStatus'], 'xml') ."\" />\r\n";
}

echo '</posts>';
?>
