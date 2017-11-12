<?php

namespace Goose\Modules\Extractors;

use Goose\Article;
use Goose\Utils\Helper;
use Goose\Traits\ArticleMutatorTrait;
use Goose\Modules\AbstractModule;
use Goose\Modules\ModuleInterface;
use DOMWrap\Document;

/**
 * Content Extractor
 *
 * @package Goose\Modules\Extractors
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache License 2.0
 */
class MetaExtractor extends AbstractModule implements ModuleInterface {
	use ArticleMutatorTrait;

	/** @var string[] */
	protected static $SPLITTER_CHARS = [
	   '|',
	   '-',
	   '»',
	   ':',
	];

	/**
	 * @param Article $article
	 */
	public function run( Article $article ) {
		$this->article( $article );

		$article->setOpenGraph( $this->getOpenGraph() );
		$article->setTitle( $this->getTitle() );
		$article->setMetaDescription( $this->getMetaDescription() );
		$article->setMetaKeywords( $this->getMetaKeywords() );
		$article->setMetaSections( $this->getMetaSections() );
		$article->setCanonicalLink( $this->getCanonicalLink() );
		$article->setLanguage( $this->getMetaLanguage() ?: $this->config()->get( 'language' ) );
		$article->setOtherVersions( $this->getOtherVersions() );
		$this->config()->set( 'language', $article->getLanguage() );
	}

	/**
	 * Retrieve all OpenGraph meta data
	 *
	 * Ported from python-goose https://github.com/grangier/python-goose/ by Xavier Grangier
	 *
	 * @return string[]
	 */
	private function getOpenGraph() {
		$results = array();

		$nodes = $this->article()->getDoc()->find( 'meta[property^="og:"]' );

		foreach ( $nodes as $node ) {
			$property = explode( ':', $node->attr( 'property' ) );
			array_shift( $property );
			$results[ implode( ':', $property ) ] = $node->attr( 'content' );
		}

		// Additionally retrieve type values based on provided og:type (http://ogp.me/#types)
		if ( isset( $results['type'] ) ) {
			$nodes = $this->article()->getDoc()->find( 'meta[property^="' . $results['type'] .':"]' );

			foreach ( $nodes as $node ) {
				$property = explode( ':', $node->attr( 'property' ) );
				array_shift( $property );
				$results[ implode( ':', $property ) ] = $node->attr( 'content' );
			}
		}

		return $results;
	}

	/**
	 * Clean title text
	 *
	 * Ported from python-goose https://github.com/grangier/python-goose/ by Xavier Grangier
	 *
	 * @param string $title
	 *
	 * @return string
	 */
	private function cleanTitle( $title ) {
		$openGraph = $this->article()->getOpenGraph();

		// Check if we have the site name in OpenGraph data
		if ( isset( $openGraph['site_name'] ) ) {
			$title = str_replace( $openGraph['site_name'], '', $title );
		}

		// Try to remove the domain from URL
		if ( $this->article()->getDomain() ) {
			$title = str_ireplace( $this->article()->getDomain(), '', $title );
		}

		// Split the title in words
		// TechCrunch | my wonderfull article
		// my wonderfull article | TechCrunch
		$titleWords = preg_split( '@[\s]+@', trim( $title ) );

		// Check for an empty title
		if ( empty( $titleWords ) ) {
			return '';
		}

		// Check if last letter is in self::$SPLITTER_CHARS
		// if so remove it
		if ( in_array( $titleWords[ count( $titleWords ) - 1 ], self::$SPLITTER_CHARS ) ) {
			array_pop( $titleWords );
		}

		// Check if first letter is in self::$SPLITTER_CHARS
		// if so remove it
		if ( isset( $titleWords[0] ) && in_array( $titleWords[0], self::$SPLITTER_CHARS ) ) {
			array_shift( $titleWords );
		}

		// Rebuild the title
		$title = trim( implode( ' ', $titleWords ) );

		return $title;
	}

	/**
	 * Get article title
	 *
	 * Ported from python-goose https://github.com/grangier/python-goose/ by Xavier Grangier
	 *
	 * @return string
	 */
	private function getTitle() {
		$openGraph = $this->article()->getOpenGraph();

		// Rely on OpenGraph in case we have the data
		if ( isset( $openGraph['title'] ) ) {
			return $this->cleanTitle( $openGraph['title'] );
		}

		$nodes = $this->getNodesByLowercasePropertyValue( $this->article()->getDoc(), 'meta', 'name', 'headline' );
		if ( $nodes->count() ) {
			return $this->cleanTitle( $nodes->first()->attr( 'content' ) );
		}

		$nodes = $this->article()->getDoc()->find( 'html > head > title' );
		if ( $nodes->count() ) {
			return $this->cleanTitle( Helper::textNormalise( $nodes->first()->text() ) );
		}

		return '';
	}

	/**
	 * @param Document $doc
	 * @param string $tag
	 * @param string $property
	 * @param string $value
	 *
	 * @return \DOMWrap\NodeList
	 */
	private function getNodesByLowercasePropertyValue( Document $doc, $tag, $property, $value ) {
		return $doc->findXPath( 'descendant::'.$tag.'[translate(@'.$property.", 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')='".$value."']" );
	}

	/**
	 * @param Document $doc
	 * @param string $property
	 * @param string $value
	 * @param string $attr
	 *
	 * @return string
	 */
	private function getMetaContent( Document $doc, $property, $value, $attr = 'content' ) {
		$nodes = $this->getNodesByLowercasePropertyValue( $doc, 'meta', $property, $value );

		if ( ! $nodes->count() ) {
			return '';
		}

		$values = [];
		foreach ( $nodes as $node ) {
			$value = $node->attr( $attr );
			$values[] = trim( $value );
		}
		$values = array_unique( $values );
		$content = implode( ',', $values );
		return $content;
	}

	/**
	 * If the article has meta language set in the source, use that
	 *
	 * @return string
	 */
	private function getMetaLanguage() {
		$lang = '';

		$el = $this->article()->getDoc()->find( 'html[lang]' );

		if ( $el->count() ) {
			$lang = $el->first()->attr( 'lang' );
		}

		if ( empty( $lang ) ) {
			$selectors = [
				'html > head > meta[http-equiv=content-language]',
				'html > head > meta[name=lang]',
			];

			foreach ( $selectors as $selector ) {
				$el = $this->article()->getDoc()->find( $selector );

				if ( $el->count() ) {
					$lang = $el->first()->attr( 'content' );
					break;
				}
			}
		}

		if ( preg_match( '@^[A-Za-z]{2}$@', $lang ) ) {
			return strtolower( $lang );
		}

		return '';
	}

	/**
	 * If the article has meta description set in the source, use that
	 *
	 * @return string
	 */
	private function getMetaDescription() {
		$desc = $this->getMetaContent( $this->article()->getDoc(), 'name', 'description' );

		if ( empty( $desc ) ) {
			$desc = $this->getMetaContent( $this->article()->getDoc(), 'property', 'og:description' );
		}

		if ( empty( $desc ) ) {
			$desc = $this->getMetaContent( $this->article()->getDoc(), 'name', 'twitter:description' );
		}

		return trim( $desc );
	}

	/**
	 * If the article has meta keywords set in the source, use that
	 *
	 * @return string
	 */
	private function getMetaKeywords() {
		$meta = [
			[ 'name', 'keywords' ],
			[ 'property', 'sailthru.tags' ],
			[ 'name', 'news_keywords' ],
			[ 'property', 'article:tag' ],
			[ 'property', 'video:tag' ],
		];
		$keywords_meta = [];
		foreach ( $meta as $search ) {
			$_meta = $this->getMetaContent( $this->article()->getDoc(), $search[0], $search[1] );
			$keywords_meta = array_merge( $keywords_meta, explode( ',', $_meta ) );
		}

		$keywords = implode( ',', array_unique( array_map( 'trim', $keywords_meta ) ) );
		return $keywords;
	}


	/**
	 * If the article has meta keywords set in the source, use that
	 *
	 * @return string
	 */
	private function getMetaSections() {
		$meta = [
			[ 'name', 'category' ],
			[ 'property', 'article:section' ],
			[ 'property', 'article:top-level-section' ],
			[ 'name', 'dfp-ad-unit-path' ],
			[ 'name', 'topics' ],
			[ 'name', 'analyticsAttributes.contentType' ],
			[ 'name', 'analyticsAttributes.topicSubChannel' ],
			[ 'name', 'page-topic' ],
			[ 'name', 'main_tag_label' ],
			[ 'name', 'channel' ],
		];

		$keywords_meta = [];
		foreach ( $meta as $search ) {
			$_meta = $this->getMetaContent( $this->article()->getDoc(), $search[0], $search[1] );
			$keywords_meta = array_merge( $keywords_meta, explode( ',' ,$_meta ) );
		}

		$keywords_js = [];
		foreach ( [ 'topic', 'topicName', 'section', 'siteHier', 'siteSection', 'articleSection', 'sections', 'hub_page' ] as $search_key ) {
			$keywords_js = array_merge( $keywords_js, $this->parse_js( $this->article()->getRawHtml(), $search_key ) );
		}

		$keywords = implode( ',', array_unique( array_map( 'trim', array_merge( $keywords_meta, $keywords_js ) ) ) );
		return $keywords;
	}


	/**
	 * If the article has other versions let's store em
	 *
	 * @return string
	 */
	private function getOtherVersions() {
		$nodes = $this->getNodesByLowercasePropertyValue( $this->article()->getDoc(), 'link', 'rel', 'amphtml' );

		if ( $nodes->count() ) {
			$other_versions['amphtml'] = trim( $nodes->first()->attr( 'href' ) );
		}

		return $other_versions;
	}

	/**
	 * If the article has meta canonical link set in the url
	 *
	 * @return string
	 */
	private function getCanonicalLink() {
		$nodes = $this->getNodesByLowercasePropertyValue( $this->article()->getDoc(), 'link', 'rel', 'canonical' );

		if ( $nodes->count() ) {
			return trim( $nodes->first()->attr( 'href' ) );
		}

		$nodes = $this->getNodesByLowercasePropertyValue( $this->article()->getDoc(), 'meta', 'property', 'og:url' );

		if ( $nodes->count() ) {
			return trim( $nodes->first()->attr( 'content' ) );
		}

		$nodes = $this->getNodesByLowercasePropertyValue( $this->article()->getDoc(), 'meta', 'name', 'twitter:url' );

		if ( $nodes->count() ) {
			return trim( $nodes->first()->attr( 'content' ) );
		}

		return $this->article()->getFinalUrl();
	}

	private function keyword_clean( $string ) {
		if ( empty( $string ) ) {
			return [];
		}
		$split = '/[,;:]+/ui';
		$string = html_entity_decode( $string );
		$keywords_raw = preg_split( $split, $string );
		$keywords = array_map( 'trim', $keywords_raw );
		$keywords = array_unique( $keywords );
		return $keywords;
	}

	private function parse_js( $html, $search_key ) {
		if ( ! preg_match( '/["|\']' . preg_quote( $search_key ) . '["|\']:\[([^\]]+)\]/i', $html, $matches ) ) {
			preg_match( '/["|\']' . preg_quote( $search_key ) . '["|\']:["|\']([^"|\']+)["|\']/i', $html, $matches );
		}

		if ( isset( $matches[1] ) ) {
			$matched_values = explode( ',', $matches[1] );
			$matched_values = array_map( function( $value ) {
				$value = str_replace( [ '"', "'" ], '', $value );
				$value = trim( $value );
				return $value;
			}, $matched_values );
			return $matched_values;
		}
		return [];
	}
}
