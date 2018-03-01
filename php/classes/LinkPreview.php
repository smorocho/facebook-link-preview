<?php
/**
 * Copyright (c) 2014 Leonardo Cardoso (http://leocardz.com)
 * Dual licensed under the MIT (http://www.opensource.org/licenses/mit-license.php)
 * and GPL (http://www.opensource.org/licenses/gpl-license.php) licenses.
 *
 * Version: 1.3.0
 */

/** Important php5-curl must be installed and enabled */
namespace smorocho\facebooklinkpreview;

include_once "Media.php";
include_once "Regex.php";
include_once "SetUp.php";
include_once "Url.php";
include_once "Content.php";
include_once "Json.php";

class LinkPreview
{
    function __construct(){}

    function joinAll($matching, $number, $url, $content)
    {
        for ($i = 0; $i < count($matching[$number]); $i++) {
            $imgSrc = $matching[$number][$i] . $matching[$number + 1][$i];
            $src = "";
            $pathCounter = substr_count($imgSrc, "../");
            if (!preg_match(Regex::$httpRegex, $imgSrc)) {
                $src = Url::getImageUrl($pathCounter, Url::canonicalLink($imgSrc, $url));
            }
            if ($src . $imgSrc != $url) {
                if ($src == "")
                    array_push($content, $src . $imgSrc);
                else
                    array_push($content, $src);
            }
        }
        return $content;
    }

    function crawl($text, $imageQuantity, $header)
    {
        if (preg_match(Regex::$urlRegex, $text, $match)) {
            $title = "";
            $description = "";
            $videoIframe = "";
            $video = "no";

            //if (strpos($match[0], " ") === 0)
            //    $match[0] = "http://" . substr($match[0], 1);

            $finalUrl = $text;
            $pageUrl = str_replace("https://", "http://", $finalUrl);

			$images = [];
            if (Content::isImage($pageUrl)) {
                $images[] = $pageUrl;
            } else {
                $urlData = $this->getPage($pageUrl);
                if (!$urlData["content"] && strpos($pageUrl, "//www.") === false) {
                    if (strpos($pageUrl, "http://") !== false)
                        $pageUrl = str_replace("http://", "http://www.", $pageUrl);
                    elseif (strpos($pageUrl, "https://") !== false)
                        $pageUrl = str_replace("https://", "https://www.", $pageUrl);

                    $urlData = $this->getPage($pageUrl);
                }

                $pageUrl = $finalUrl = $urlData["url"];
                $raw = $urlData["content"];
                $header = $urlData["header"];
				$headers = $urlData["headers"];

                $metaTags = Content::getMetaTags($raw);

                $tempTitle = Content::extendedTrim($metaTags["title"]);
                if ($tempTitle != "")
                    $title = $tempTitle;

                if ($title == "") {
                    if (preg_match(Regex::$titleRegex, str_replace("\n", " ", $raw), $matching))
                        $title = $matching[2];
                }

                $tempDescription = Content::extendedTrim($metaTags["description"]);
                if ($tempDescription != "")
                    $description = $tempDescription;
                else
                    $description = Content::crawlCode($raw);

                $descriptionUnderstood = false;
                if ($description != "")
                    $descriptionUnderstood = true;

                if (($descriptionUnderstood == false && strlen($title) > strlen($description) && !preg_match(Regex::$urlRegex, $description) && $description != "" && !preg_match('/[A-Z]/', $description)) || $title == $description) {
                    $title = $description;
                    $description = Content::crawlCode($raw);
                }

                if(Content::isJson($title)){
                    $title = "";
                }
                if(Content::isJson($description)){
                    $description = "";
                }

                $media = self::getMedia($pageUrl);
                if(count($media) == 0) {
                	foreach($metaTags['images'] as $metaImage) {
                		$images[] = !preg_match(Regex::$httpRegex, $metaImage) ? Url::canonicalLink(Content::extendedTrim($metaImage), $pageUrl) : $metaImage;
                	}
                }
                else {
                	$images[] = $media[0];
					$videoIframe = $media[1];
                }

                $images = array_merge($images, Content::getImages($raw, $pageUrl, $imageQuantity));
				$images = array_keys(array_flip($images));// filter out duplicate image urls
                if ($media != null && $media[0] != "" && $media[1] != "")
                    $video = "yes";

                $title = Content::extendedTrim($title);
                $pageUrl = Content::extendedTrim($pageUrl);
                $description = Content::extendedTrim($description);

                $description = preg_replace(Regex::$scriptRegex, "", $description);
            }

            $finalLink = explode("&", $finalUrl);
            $finalLink = $finalLink[0];

            $description = strip_tags($description);

            $can_brand = true;
            if (isset($headers["x-frame-options"]) && $headers["x-frame-options"] == "SAMEORIGIN")
            	$can_brand = false;

            $answer = array("title" => $title, "url" => $finalLink, "pageUrl" => $finalUrl, "canonicalUrl" => Url::canonicalPage($pageUrl), "description" => $description,
                "images" => $images, "video" => $video, "videoIframe" => $videoIframe, "canBrand" => $can_brand);

            $result_json = Json::jsonSafe($answer, $header);
            $result_json_decoded = json_decode($result_json);

            $flagged = false;

            if (!isset($result_json_decoded->title)) {
                $title = utf8_encode($title);
                $flagged = true;
            }

            if (!isset($result_json_decoded->description)) {
                $description = utf8_encode($description);
                $flagged = true;
            }

            if ($flagged) {
                $answer = array("title" => $title, "url" => $finalLink, "pageUrl" => $finalUrl, "canonicalUrl" => Url::canonicalPage($pageUrl), "description" => $description,
                    "images" => $images, "video" => $video, "videoIframe" => $videoIframe, "canBrand" => $can_brand);

                return Json::jsonSafe($answer, $header);
            } else {
                return $result_json;
            }
        }
        return null;
    }

    function getPage($url)
    {
        $res = array();
        $options = array(
			CURLOPT_RETURNTRANSFER => true, // return web page
            CURLOPT_HEADER => true, // return headers
            CURLOPT_FOLLOWLOCATION => true, // follow redirects
            CURLOPT_USERAGENT => "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/43.0.2357.134 Safari/537.36",
            CURLOPT_AUTOREFERER => true, // set referer on redirect
            CURLOPT_CONNECTTIMEOUT => 120, // timeout on connect
            CURLOPT_TIMEOUT => 120, // timeout on response
            CURLOPT_MAXREDIRS => 10, // stop after 10 redirects
            CURLOPT_ENCODING => "utf-8"// sets "Accept-Encoding: " header to all supported encoding types
        );
        $ch = curl_init($url);
        curl_setopt_array($ch, $options);
        $content = curl_exec($ch);
        $header = curl_getinfo($ch);
        curl_close($ch);

        $curlHeaderSize=$header['header_size'];
		$body = trim(mb_substr($content, $curlHeaderSize));
		$ResponseHeader = explode("\n",trim(mb_substr($content, 0, $curlHeaderSize)));
		unset($ResponseHeader[0]);
		$aHeaders = array();
		foreach($ResponseHeader as $line){
			if (strpos($line,':') != false) {
				list($key,$val) = explode(':',$line,2);
				$aHeaders[strtolower($key)] = trim($val);
			}
		}

        $hrd = $header["content_type"];
        header("Content-Type: " . $hrd, true);

        $res['content'] = $body;
        $res['url'] = $header['url'];
        $res['header'] = $hrd;
		$res['headers'] = $aHeaders;
        return $res;
    }

    public static function getMedia($pageUrl)
    {
        $media = array();
        if (strpos($pageUrl, "youtube.com") !== false) {
            $media = Media::mediaYoutube($pageUrl);
        } else if (strpos($pageUrl, "vimeo.com") !== false) {
            $media = Media::mediaVimeo($pageUrl);
        } else if (strpos($pageUrl, "vine.co") !== false) {
            $media = Media::mediaVine($pageUrl);
        } else if (strpos($pageUrl, "metacafe.com") !== false) {
            $media = Media::mediaMetacafe($pageUrl);
        } else if (strpos($pageUrl, "dailymotion.com") !== false) {
            $media = Media::mediaDailymotion($pageUrl);
        } else if (strpos($pageUrl, "collegehumor.com") !== false) {
            $media = Media::mediaCollegehumor($pageUrl);
        } else if (strpos($pageUrl, "blip.tv") !== false) {
            $media = Media::mediaBlip($pageUrl);
        } else if (strpos($pageUrl, "funnyordie.com") !== false) {
            $media = Media::mediaFunnyordie($pageUrl);
        }
        return $media;
    }
}
?>