<?php
/***************************************************************************
 Copyright (C) 2004 - 2006 Scuttle project
 http://sourceforge.net/projects/scuttle/
 http://scuttle.org/

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with this program; if not, write to the Free Software
 Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 ***************************************************************************/

require_once 'www-header.php';

/**
 * Define a constant to a key/value if present in associative array
 * or to a default value.
 *
 * @param string $name    Name of constant.
 * @param array  $array   Typically $_GET or $_POST.
 * @param string $key     Associated key.
 * @param mixed  $default Default value, optional. Defaults to empty string.
 * @param bool   $case    Whether to define constant as case-insensitive.
 *
 * @access public
 *
 * @return void
 */
function defineWithDefault($name, $array, $key, $default = '', $case = false)
{
    if (isset($array[$key])) {
        return define($name, $array[$key], (bool) $case);
    } else {
        return define($name, $default, (bool) $case);
    }
}

/**
 * Filter tags based on whether they are already used by the current user.
 *
 * @param array   $tags       Array of tags
 * @param boolean $includeAll Whether to exclude tags not present in db, or
 * adjust resultset.
 *
 * @return array
 */
function filterTags($tags, $includeAll = false)
{
    $b2tservice = SemanticScuttle_Service_Factory::get('Bookmark2Tag');
    $rTags = $b2tservice->getChoiceTags($tags);
    /** proof of concept - later just return rTags for a tag cloud representation */
    $ret = array();
    $found = array();
    foreach ($rTags as $rTag) {
        $rTag['bCount']++;
        $ret[] = $rTag;
        $found[] = $rTag['tag'];
    }

    if ($includeAll) {
        $not_found = array_diff($tags, $found);
        foreach ($not_found as $element) {
            $ret[] = array('bCount' => 0, 'tag' => $element);
        }
    }
    return $ret;
}

/* Service creation: only useful services are created */
$bookmarkservice = SemanticScuttle_Service_Factory::get('Bookmark');
$cacheservice = SemanticScuttle_Service_Factory::get('Cache');
$tagextractorservice = SemanticScuttle_Service_Factory::get('TagExtractor');

/* Managing all possible inputs */
defineWithDefault('GET_ACTION',       $_GET,  'action');
defineWithDefault('GET_ADDRESS',      $_GET,  'address');
defineWithDefault('GET_COPYOF',       $_GET,  'copyOf');
defineWithDefault('GET_DESCRIPTION',  $_GET,  'description');
defineWithDefault('GET_PAGE',         $_GET,  'page', 0);
defineWithDefault('GET_POPUP',        $_GET,  'popup');
defineWithDefault('GET_PRIVATENOTE',  $_GET,  'privateNote');
defineWithDefault('GET_SORT',         $_GET,  'sort');
defineWithDefault('GET_TAGS',         $_GET,  'tags');
defineWithDefault('GET_TITLE',        $_GET,  'title');
defineWithDefault('POST_ADDRESS',     $_POST, 'address');
defineWithDefault('POST_DESCRIPTION', $_POST, 'description');
defineWithDefault('POST_POPUP',       $_POST, 'popup');
defineWithDefault('POST_PRIVATENOTE', $_POST, 'privateNote');
defineWithDefault('POST_REFERRER',    $_POST, 'referrer');
defineWithDefault('POST_STATUS',      $_POST, 'status');
defineWithDefault('POST_SUBMITTED',   $_POST, 'submitted');
defineWithDefault('POST_TITLE',       $_POST, 'title');

if (!isset($_POST['tags'])) {
    $_POST['tags'] = array();
}
//echo '<p>' . var_export($_POST, true) . '</p>';die();


if ((GET_ACTION == "add") && !$userservice->isLoggedOn()) {
    $loginqry = str_replace("'", '%27', stripslashes($_SERVER['QUERY_STRING']));
    header('Location: '. createURL('login', '?'. $loginqry));
    exit();
}

if ($userservice->isLoggedOn()) {
    $currentUser = $userservice->getCurrentObjectUser();
    $currentUserID = $currentUser->getId();
    $currentUsername = $currentUser->getUsername();
}


@list($url, $user, $cat) = isset($_SERVER['PATH_INFO']) ?
    explode('/', $_SERVER['PATH_INFO']) : null;


$endcache = false;
if ($usecache) {
    // Generate hash for caching on
    $hash = md5($_SERVER['REQUEST_URI'] . $user);

    // Don't cache if its users' own bookmarks
    if ($userservice->isLoggedOn()) {
        if ($currentUsername != $user) {
            // Cache for 5 minutes
            $cacheservice->Start($hash);
            $endcache = true;
        }
    } else {
        // Cache for 30 minutes
        $cacheservice->Start($hash, 1800);
        $endcache = true;
    }
}

$pagetitle = $rssCat = $catTitle = '';
if ($user) {
    if (is_int($user)) {
        $userid = intval($user);
    } else {
        if (!($userinfo = $userservice->getUserByUsername($user))) {
            $tplVars['error'] = sprintf(
                T_('User with username %s was not found'),
                $user
            );
            $templateservice->loadTemplate('error.404.tpl', $tplVars);
            exit();
        } else {
            $userid = $userinfo['uId'];
        }
    }
    $pagetitle .= ': '. $user;
}
if ($cat) {
    $catTitle = ': '. str_replace('+', ' + ', $cat);

    $catTitleWithUrls = ': ';
    $titleTags = explode('+', filter($cat));
    $title = T_('Remove the tag from the selection');
    for ($i = 0; $i<count($titleTags); $i++) {
        $url = createUrl(
            'bookmarks',
            $user . '/' . aggregateTags($titleTags, '+', $titleTags[$i])
        );
        $catTitleWithUrls .= $titleTags[$i]
                          . "<a href='$url' title='$title'>*</a> + ";
    }
    $catTitleWithUrls = substr(
        $catTitleWithUrls,
        0,
        strlen($catTitleWithUrls) - strlen(' + ')
    );

    $pagetitle .= $catTitleWithUrls;
} else {
    $catTitleWithUrls = '';
}
$pagetitle = substr($pagetitle, 2);

// Header variables
$tplVars['loadjs'] = true;

// ADD A BOOKMARK
$saved = false;
$templatename = 'bookmarks.tpl';
if ($userservice->isLoggedOn() && POST_SUBMITTED != '') {
    if (!POST_TITLE || !POST_ADDRESS) {
        $tplVars['error'] = T_('Your bookmark must have a title and an address');
        $templatename = 'editbookmark.tpl';
    } else {
        $address = trim(POST_ADDRESS);
        if (!SemanticScuttle_Model_Bookmark::isValidUrl($address)) {
            $tplVars['error'] = T_('This bookmark URL may not be added');
            $templatename = 'editbookmark.tpl';
        } else if ($bookmarkservice->bookmarkExists($address, $currentUserID)) {
            // If the bookmark exists already, edit the original
            $bookmark = $bookmarkservice->getBookmarkByAddress($address);
            header('Location: '. createURL('edit', $bookmark['bId']));
            exit();
            // If it's new, save it
        } else {
            $title = trim(POST_TITLE);
            $description = trim(POST_DESCRIPTION);
            $privateNote = trim(POST_PRIVATENOTE);
            $status = intval(POST_STATUS);
            $categories = explode(',', $_POST['tags']);
            $saved = true;
            if ($bookmarkservice->addBookmark(
                $address,
                $title,
                $description,
                $privateNote,
                $status,
                $categories
            )) {
                if (POST_POPUP != '') {
                    $tplVars['msg'] = '<script type="text/javascript">'
                                    . 'window.close();</script>';
                } else {
                    $tplVars['msg'] = T_('Bookmark saved')
                                    . ' <a href="javascript:history.go(-2)">'
                                    . T_('(Come back to previous page.)')
                                    . '</a>';
                    // Redirection option
                    if ($GLOBALS['useredir']) {
                        $address = $GLOBALS['url_redir'] . $address;
                    }
                }
            } else {
                $tplVars['error'] = T_(
                    'There was an error saving your bookmark. ' .
                    'Please try again or contact the administrator.'
                );
                $templatename = 'editbookmark.tpl';
                $saved = false;
            }
        }
    }
}

if (GET_ACTION == "add") {
    // If the bookmark exists already, edit the original
    if ($bookmarkservice->bookmarkExists(
        stripslashes(GET_ADDRESS),
        $currentUserID
    )) {
        $bookmark = $bookmarkservice->getBookmarks(
            0,
            null,
            $currentUserID,
            null,
            null,
            null,
            null,
            null,
            null,
            $bookmarkservice->getHash(stripslashes(GET_ADDRESS))
        );
        $popup = (GET_POPUP!='') ? '?popup=1' : '';
        $url = createURL('edit', $bookmark['bookmarks'][0]['bId'] . $popup);
        header('Location: '. $url);
        exit();
    }
    $templatename = 'editbookmark.tpl';
}

if ($templatename == 'editbookmark.tpl') {
    if ($userservice->isLoggedOn()) {
        $tplVars['formaction']  = createURL('bookmarks', $currentUsername);
        if (POST_SUBMITTED != '') {
            $tplVars['row'] = array(
                'bTitle' => stripslashes(POST_TITLE),
                'bAddress' => stripslashes(POST_ADDRESS),
                'bDescription' => stripslashes(POST_DESCRIPTION),
                'bPrivateNote' => stripslashes(POST_PRIVATENOTE),
                'tags' => ($_POST['tags'] ? $_POST['tags'] : array()),
                'bStatus' => $GLOBALS['defaults']['privacy'],
            );
            $tplVars['tags'] = $_POST['tags'];
        } else {
            if (GET_COPYOF != '') {  //copy from bookmarks page
                $tplVars['row'] = $bookmarkservice->getBookmark(
                    intval(GET_COPYOF), true
                );
                if (!$currentUser->isAdmin()) {
                    //only admin can copy private note
                    $tplVars['row']['bPrivateNote'] = '';
                }
            } else {
                //copy from pop-up bookmarklet
                $extractedTags = array();
                if ($GLOBALS['tagExtraction']) {
                    $extractedTags = $tagextractorservice->extractFromUrl(
                        stripslashes(GET_ADDRESS)
                    );
                    $filteredTags = filterTags($extractedTags, $bIncludeAll = true);
                    $extractedTags = $filteredTags;
                }
                $tplVars['row'] = array(
                    'bTitle' => stripslashes(GET_TITLE),
                    'bAddress' => stripslashes(GET_ADDRESS),
                    'bDescription' => stripslashes(GET_DESCRIPTION),
                    'bPrivateNote' => stripslashes(GET_PRIVATENOTE),
                    'tags' => (
                        GET_TAGS ?
                        explode(',', stripslashes(GET_TAGS)) : array()
                    ),
                    'extractedTags' => $extractedTags,
                    'bStatus' => $GLOBALS['defaults']['privacy']
                );
            }

        }
        $title = T_('Add a Bookmark');
        $tplVars['referrer'] = '';;
        if (isset($_SERVER['HTTP_REFERER'])) {
            $tplVars['referrer'] = $_SERVER['HTTP_REFERER'];
        }
        $tplVars['pagetitle'] = $title;
        $tplVars['subtitle'] = $title;
        $tplVars['btnsubmit'] = T_('Add Bookmark');
        $tplVars['popup'] = (GET_POPUP!='') ? GET_POPUP : null;
    } else {
        $tplVars['error'] = T_(
            'You must be logged in before you can add bookmarks.'
        );
    }
} else if ($user && GET_POPUP == '') {

    $tplVars['sidebar_blocks'] = array('watchstatus');

    if (!$cat) { //user page without tags
        $rssTitle = "My Bookmarks";
        $cat = null;
        $tplVars['currenttag'] = null;
        //$tplVars['sidebar_blocks'][] = 'menu2';
        $tplVars['sidebar_blocks'][] = 'linked';
        $tplVars['sidebar_blocks'][] = 'popular';
    } else { //pages with tags
        $rssTitle = "Tags" . $catTitle;
        $rssCat = '/'. filter($cat, 'url');
        $tplVars['currenttag'] = $cat;
        $tplVars['sidebar_blocks'][] = 'tagactions';
        //$tplVars['sidebar_blocks'][] = 'menu2';
        $tplVars['sidebar_blocks'][] = 'linked';
        $tplVars['sidebar_blocks'][] = 'related';
        /*$tplVars['sidebar_blocks'][] = 'menu';*/
    }
    $tplVars['sidebar_blocks'][] = 'menu2';
    $tplVars['popCount'] = 30;
    //$tplVars['sidebar_blocks'][] = 'popular';

    $tplVars['userid'] = $userid;
    $tplVars['userinfo'] = $userinfo;
    $tplVars['user'] = $user;
    $tplVars['range'] = 'user';

    // Pagination
    $perpage = getPerPageCount($currentUser);
    if (intval(GET_PAGE) > 1) {
        $page = intval(GET_PAGE);
        $start = ($page - 1) * $perpage;
    } else {
        $page = 0;
        $start = 0;
    }

    // Set template vars
    $tplVars['rsschannels'] = array(
        array(
            sprintf(T_('%s: %s'), $sitename, $rssTitle),
            createURL('rss', filter($user, 'url'))
            . $rssCat . '?sort='.getSortOrder()
        )
    );

    if ($userservice->isLoggedOn()) {
        $currentUsername = $currentUser->getUsername();
        if ($userservice->isPrivateKeyValid($currentUser->getPrivateKey())) {
            array_push(
                $tplVars['rsschannels'],
                array(
                    sprintf(
                        T_('%s: %s (+private %s)'),
                        $sitename, $rssTitle, $currentUsername
                    ),
                    createURL('rss', filter($currentUsername, 'url'))
                    . $rssCat
                    . '?sort=' . getSortOrder()
                    . '&privateKey=' . $currentUser->getPrivateKey()
                )
            );
        }
    }

    $tplVars['page'] = $page;
    $tplVars['start'] = $start;
    $tplVars['bookmarkCount'] = $start + 1;

    $bookmarks = $bookmarkservice->getBookmarks(
        $start,
        $perpage,
        $userid,
        $cat,
        null,
        getSortOrder()
    );
    $tplVars['total'] = $bookmarks['total'];
    $tplVars['bookmarks'] = $bookmarks['bookmarks'];
    $tplVars['cat_url'] = createURL('bookmarks', '%s/%s');
    $tplVars['nav_url'] = createURL('bookmarks', '%s/%s%s');
    if ($userservice->isLoggedOn() && $user == $currentUsername) {
        $tplVars['pagetitle'] = T_('My Bookmarks') . $catTitle;
        $tplVars['subtitlehtml'] =  T_('My Bookmarks') . $catTitleWithUrls;
    } else {
        $tplVars['pagetitle']    = $user.': '.$cat;
        $tplVars['subtitlehtml'] =  $user . $catTitleWithUrls;
    }
}

$tplVars['summarizeLinkedTags'] = true;
$tplVars['pageName'] = PAGE_BOOKMARKS;

$templateservice->loadTemplate($templatename, $tplVars);

if ($usecache && $endcache) {
    // Cache output if existing copy has expired
    $cacheservice->End($hash);
}
?>
