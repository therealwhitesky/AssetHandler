<?php

namespace Nulumia\XFOptimize;

use DOMDocument;
use DOMXpath;
use XF\Http\Request;

class AssetHandler
{
    public static function processAssets($html, $template)
    {
		// Setup vars
		$options = \XF::options();
		
		// Get site host for asset checks
		if ($options->boardUrl !== null)
		{
			$siteHost = $options->boardUrl;
		}
		else
		{
			$siteHost = $_SERVER['HTTP_HOST'];
		}
		
		// Error
		if (isset($siteHost))
		{
			$siteHost = parse_url($siteHost);
			
			if (isset($siteHost['host']))
			{
				$siteHostRootHost = $siteHost['host'];
			}
			else
			{
				throw new \LogicException('Could not detect a valid host using your board URL or server path. Please check configuration.');
			}
		}
		else
		{
			throw new \LogicException('Could not detect a valid host using your board URL or server path. Please check configuration.');
		}
		
		// Most priority templates are handled separately by controller extensions where we grab specific images,
		// such as full media or resource description full attachments.
		$handledTemplates = [
			'thread_view',
			'xfmg_media_view',
			'xfrm_resource_view',
			'dbtech_ecommerce_product_view'
		];
		// Here we can add additional templates to lazy-fetch some images
		$priorityTemplates = [
			'page_view',
			'EWRmedio_medias_list'
		];
		if (isset($options->nulumiaXFOptimizePriorityPreloadAddTemplates))
		{
			$additionalTemplates = explode("\n", $options->nulumiaXFOptimizePriorityPreloadAddTemplates);
			$priorityTemplates = array_unique(array_merge($priorityTemplates, $additionalTemplates), SORT_REGULAR);
		}
		
		//var_dump($template);
		// Setup directives
		$isPreconnect = $options->nulumiaXFOptimizeAutomaticPreconnect;
		
		$isPriorityPreload = $options->nulumiaXFOptimizePreloadMode == 'priority' && in_array($template, $priorityTemplates) && !in_array($template, $handledTemplates);
		$isManualPreload = $options->nulumiaXFOptimizePreloadMode == 'manual' || $isPriorityPreload;
		// We only test for two conditions here, as Posts mode is handled separately
		$isPreload = $isManualPreload || $isPriorityPreload;
		$isAutoPreload = $options->nulumiaXFOptimizePreloadMode == 'auto';
		
		// Setup arrays
		$imageUrlsFromTags = [];
		$imageUrlsFromBgStyles = [];
		$imageUrlsFromDataAttr = [];
		$images = [];
		
		// Ignore image sources with these matches. We're still catching a broad net for hosts
		$disallowedInitial = [
			'base64',
			'blank.gif',
			];
			
		// Once hosts has been compiled, strip these images from preloading
		$disallowedFinal = [
			//'icon',
			'avatar',
			'/thumbnail/',
			'thumbnail_url',
			'data/attachments',
			'productIcons'
			];
		
		// Are we running preconnect
		if ($isPreconnect)
		{
			$hosts = [];
		}
		
		// Setup DOM
        $dom = new DOMDocument();
		libxml_use_internal_errors(true);
		@$dom->loadHTML($html);

		// If either of the following options are enabled, we need to get all image URLs
		if ($isPreconnect || $isAutoPreload)
		{
			// From tags
			
			$domImages = $dom->getElementsByTagName("img"); // DOMNodeList Object	
			$imageUrlsFromTags = self::getAssetUrls($domImages, 'img', $siteHostRootHost, $disallowedInitial);
			
			// From background styles
			
			$xpath = new DOMXPath($dom);
			$styleElements = $xpath->query('//*[@style]');
			$imageUrlsFromStyles = self::getAssetUrls($styleElements, 'bg-style', $siteHostRootHost, $disallowedInitial);
		}
		
		// Preconnect scripts and links
		if ($isPreconnect)
		{
			// Get scripts
			$domScripts = $dom->getElementsByTagName("script"); // DOMNodeList Object	
			$scriptUrls = self::getAssetUrls($domScripts, 'script', $siteHostRootHost);
			
			// Get iframes
			$domIframes = $dom->getElementsByTagName("iframe"); // DOMNodeList Object	
			$iframeUrls = self::getAssetUrls($domIframes, 'iframe', $siteHostRootHost);
			
			// Get link tags
			$domLinks = $dom->getElementsByTagName("link"); // DOMNodeList Object	
			$linkUrls = self::getAssetUrls($domLinks, 'link', $siteHostRootHost);
		}
		
		// Manual preload
		if ($isManualPreload)
		{
			// Find elements with the data-preload attribute
			$xpath = new DOMXPath($dom);
			$preloadElements = $xpath->query('//*[@data-preload]');

			$manualPreloadImages = [];

			foreach ($preloadElements as $item)
			{
				// Find images or background styles
				// We run this as OR statement vs sequentially as we want occurances in order in the dom to utilize $limi
				if ($item->nodeName == 'img' || ($item->nodeType == '1' && $item->hasAttribute("style")))
				{
					$manualPreloadImages[] = $item;
				}
				else
				{
					// Element is a container
					$childNodes = $item->childNodes;
					foreach ($childNodes as $item)
					{
						// Find images or background styles
						if ($item->nodeName == 'img' || ($item->nodeType == '1' && $item->hasAttribute("style")))
						{
							$manualPreloadImages[] = $item;
						}
					}
				}
			}
			$imageUrlsFromDataAttr = self::getAssetUrls($manualPreloadImages, 'data-preload', $siteHostRootHost, $disallowedInitial);
		}

		// Priority preload for page nodes
		if ($isPriorityPreload)
		{

			// Get all elements or background styles within the page body
			$classname = "p-body-pageContent";
			$priorityPreloadElements = $xpath->query("//div[@class='p-body-pageContent']//img|//*[@style]");

			$priorityPreloadImages = [];

			foreach ($priorityPreloadElements as $item)
			{
				// Find images or background styles
				// We run this as OR statement vs sequentially as we want occurances in order in the dom to utilize $limit
				if ($item->nodeName == 'img' || ($item->nodeType == '1' && $item->hasAttribute("style")))
				{
					$priorityPreloadImages[] = $item;
				}
				else
				{
					// Element is a container
					$childNodes = $item->childNodes;
					foreach ($childNodes as $item)
					{
						// Find images or background styles
						if ($item->nodeName == 'img' || ($item->nodeType == '1' && $item->hasAttribute("style")))
						{
							$priorityPreloadImages[] = $item;
						}
					}
				}
			}
			$imageUrlsFromPriority = self::getAssetUrls($priorityPreloadImages, 'priority', $siteHostRootHost, $disallowedInitial);
		}
		
		// Compile images for hosts - as this ignores what preloading method we're using, this may not be what's used for preload output
		if ($isPreconnect)
		{
			$images = array_unique(array_merge($imageUrlsFromTags, $imageUrlsFromStyles, $imageUrlsFromDataAttr), SORT_REGULAR);
		}
		
		// Dev override
		$disableLocalHost = true;
		
		// Compile hosts
		if ($isPreconnect)
		{
			foreach($images as $image) {
				if (isset($image['host']) && self::checkLocalHost($image['host'], $siteHostRootHost))
				{
					$hash = $image['host'];
					$hosts[$hash] = $image;
				}
			}
			$hosts = array_unique(array_merge($hosts, $scriptUrls, $linkUrls, $iframeUrls), SORT_REGULAR);
		}
		
		// Do we need to replace the compiled images with manual only?
		if ($isManualPreload && !$isPriorityPreload)
		{
			$images = $imageUrlsFromDataAttr;
		}
		
		// Do we need to replace the compiled images with manual + priority?
		if ($isPriorityPreload)
		{
			$images = array_unique(array_merge($imageUrlsFromDataAttr, $imageUrlsFromPriority), SORT_REGULAR);
		}
		
		// Remove final disallowed images
		if ($isPreload)
		{
			$images = self::removeDisallowed($images, $disallowedFinal);
			
			// Limit
			$limit = $options->nulumiaXFOptimizePreloadLimitPerContent['enabled']
				? $options->nulumiaXFOptimizePreloadLimitPerContent['attachments']
				: null;
			
			if ($limit)
			{
				$images = array_slice($images, 0, $limit);
			}
		}
		
        if ($isPreload)
		{
			$html = self::setPreloadTags($html, $images);
		}
		if ($isPreconnect)
		{
			$html = self::setPreconnectTags($html, $hosts);
		}
        return $html;
    }
	
	protected static function getAssetUrls($elements, string $from, string $siteHostRootHost, array $disallowed = [])
	{
		$assetUrls = [];
		
		foreach ($elements as $item)
		{
			// Process background styles
			if ($from == 'bg-style'
				|| ($from == 'data-preload' && $item->nodeName !== 'img' && $item->hasAttribute("style"))
				|| ($from == 'priority' && $item->nodeName !== 'img' && $item->hasAttribute("style"))
				)
			{
				$style = $item->getAttribute("style");
				// Grab the background image with extension from the entire string
				preg_match('~(?:\([\'"]?)(.*?.(jpg|jpeg|png|gif|webp|JPG|JPEG|PNG|GIF|WEBP))(?:[\'"]?\))~', $style, $match);
				// Found an image and doesn't include disallowed
				if ($match && !self::strposa($match[1], $disallowed))
				{
					$src = $match[1];
					$url = parse_url($src);
					// Add to array
					if (!in_array($url, $assetUrls))
					{
						$url['from'] = $from;
						$assetUrls[] = $url;
					}
				}
			}
			// Process image tags
			if (($from == 'img' && $item->hasAttribute("src"))
				|| ($from == 'data-preload' && $item->nodeName == 'img' && $item->hasAttribute("src"))
				|| ($from == 'priority' && $item->nodeName == 'img' && $item->hasAttribute("src"))
				)
			{
				$src = $item->getAttribute("src");
				// Don't include avatars
				if (!self::strposa($src, $disallowed))
				{
					$url = parse_url($src);
					// Add to array
					if (!in_array($url, $assetUrls))
					{
						$url['from'] = $from;
						$assetUrls[] = $url;
					}
				}
			}
			// Process iframes
			if ($from == 'iframe' && $item->hasAttribute("src"))
			{
				$src = $item->getAttribute("src");
				$url = parse_url($src);
				// Add to array
				if (!in_array($url, $assetUrls) && self::checkLocalHost($url['host'], $siteHostRootHost))
				{
					$url['from'] = $from;
					$assetUrls[] = $url;
				}
			}
			// Process script tags
			if ($from == 'script' && $item->hasAttribute("src"))
			{
				$src = $item->getAttribute("src");
				//print($src);
				$url = parse_url($src);
				// Add to array
				if (isset($url['host']) && self::checkLocalHost($url['host'], $siteHostRootHost))
				{
					$url['from'] = $from;
					$hash = $url['host'];
					$assetUrls[$hash] = $url;
				}
			}
			// Process link tags
			if ($from == 'link' && $item->hasAttribute("href"))
			{
				$src = $item->getAttribute("href");
				$url = parse_url($src);
				// Add to array
				if (isset($url['host']) && self::checkLocalHost($url['host'], $siteHostRootHost))
				{
					$url['from'] = $from;
					$hash = $url['host'];
					$assetUrls[$hash] = $url;
				}
			}
		}
    
		return $assetUrls;
	}
	
	protected static function removeDisallowed(array $images, array $disallowed)
	{
		foreach ($images as $key => $value)
		{
			if (self::strposa($value['path'], $disallowed))
			{
				unset($images[$key]);
			}
		}
		return $images;
	}
	
	protected static function checkLocalHost(string $host, string $siteHostRootHost)
	{
		if (strpos($host, $siteHostRootHost) !== false)
		{
			return false;
		}
		return true;
	}
	
	protected static function strposa($haystack, $needle, $offset=0)
	{
		if (!is_array($needle)) $needle = array($needle);
		foreach($needle as $query) {
			if(strpos($haystack, $query, $offset) !== false) return true; // stop on first true result
		}
		return false;
	}

    protected static function setPreloadTags($html, array $images)
    {
		if ($images && !empty($images))
		{
			foreach ($images as $image) {
				$html = str_replace('</head>', '<link rel="preload" data-from="' . $image['from'] . '" as="image" href="' . (isset($image['host']) ? '//' . $image['host'] : '') . $image['path'] . '"></head>', $html);
			}
		}
        return $html;
    }
	
	protected static function setPreconnectTags($html, array $hosts)
    {
		if ($hosts && !empty($hosts))
		{
			foreach ($hosts as $host) {
				$html = str_replace('</title>', '</title><link rel="preconnect" data-from="' . $host['from'] . '" href="' . $host['host'] . '">', $html);
			}
		}
        return $html;
    }
}
