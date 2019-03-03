<?php

namespace SMW\Elastic\Indexer\Replication;

use Onoi\Cache\Cache;
use SMW\Store;
use SMW\DIWikiPage;
use SMW\DIProperty;
use SMW\MediaWiki\Api\Tasks\Task;
use SMW\Message;
use SMW\EntityCache;
use Html;

/**
 * @license GNU GPL v2+
 * @since 3.1
 *
 * @author mwjames
 */
class CheckReplicationTask extends Task {

	/**
	 * @var Store
	 */
	private $store;

	/**
	 * @var ReplicationStatus
	 */
	private $replicationStatus;

	/**
	 * @var EntityCache
	 */
	private $entityCache;

	/**
	 * @var boolean
	 */
	private $isRTL = false;

	/**
	 * @var boolean
	 */
	private $errorTitle = '';

	/**
	 * @var integer
	 */
	private $cacheTTL = 3600;

	/**
	 * @since 3.1
	 *
	 * @param Store $store
	 * @param ReplicationStatus $replicationStatus
	 * @param EntityCache $entityCache
	 */
	public function __construct( Store $store, ReplicationStatus $replicationStatus, EntityCache $entityCache ) {
		$this->store = $store;
		$this->replicationStatus = $replicationStatus;
		$this->entityCache = $entityCache;
	}

	/**
	 * @since 3.1
	 *
	 * @param DIWikiPage $subject
	 *
	 * @return string
	 */
	public static function makeCacheKey( $subject ) {
		return EntityCache::makeCacheKey( 'es-replication-check', $subject->getHash() );
	}

	/**
	 * @since 3.1
	 *
	 * @param integer $cacheTTL
	 */
	public function setCacheTTL( $cacheTTL ) {
		$this->cacheTTL = $cacheTTL > 0 ? $cacheTTL : 3600;
	}

	/**
	 * @since 3.1
	 *
	 * @param array $parameters
	 *
	 * @return array
	 */
	public function process( array $parameters ) {

		if ( $parameters['subject'] === '' ) {
			return [ 'done' => false ];
		}

		$subject = DIWikiPage::doUnserialize(
			$parameters['subject']
		);

		$html = $this->checkReplication(
			$subject,
			$parameters
		);

		return [ 'done' => true, 'html' => $html ];
	}

	/**
	 * @since 3.1
	 *
	 * @param DIWikiPage $subject
	 * @param array $options
	 *
	 * @return string
	 */
	public function checkReplication( DIWikiPage $subject, array $options = [] ) {

		$this->isRTL = isset( $options['dir'] ) && $options['dir'] === 'rtl';
		$html = '';

		$id = $this->store->getObjectIds()->getID( $subject );
		$title = $subject->getTitle();

		// Find the time from elastic
		$dataItem = $this->replicationStatus->getModificationDate(
			$id
		);

		// What is stored in the DB
		$pv = $this->store->getPropertyValues(
			$subject,
			new DIProperty( '_MDAT' )
		);

		if ( $dataItem === false || $pv === [] ) {
			$html = $this->replicationErrorMsg( $title->getPrefixedText(), $id );
		} elseif ( !end( $pv )->equals( $dataItem ) ) {
			$dates = [
				'time_es' => $dataItem->asDateTime()->format( 'Y-m-d H:i:s' ),
				'time_store' => end( $pv )->asDateTime()->format( 'Y-m-d H:i:s' )
			];
			$html = $this->replicationErrorMsg( $title->getPrefixedText(), $id, $dates );
		} elseif ( $subject->getNamespace() === NS_FILE ) {
			$html = $this->checkFileIngest( $subject );
		}

		$key = $this->makeCacheKey( $subject );

		// Only keep the cache around when ES has successful replicated the entity
		if ( $html === '' ) {
			$this->entityCache->save( $key, 'success', $this->cacheTTL );
			$this->entityCache->associate( $subject, $key );
		} else {
			$this->entityCache->delete( $key );
		}

		return $this->wrapHTML( $html );
	}

	private function replicationErrorMsg( $title_text, $id, $dates = [] ) {

		$content = '';
		$this->errorTitle = 'smw-es-replication-error';

		if ( $dates !== [] ) {
			$content .= $this->msg( [ 'smw-es-replication-error-divergent-date', $title_text, $id ] );
			$content .= '--LINE--';
			$content .= $this->msg( [ 'smw-es-replication-error-divergent-date-detail', $dates['time_es'], $dates['time_store'] ], Message::PARSE );
			$content .= '--LINE--';
			$content .= '<span style="font-size:12px;">' . $this->msg( 'smw-es-replication-error-suggestions' ) . '</span>';
		} else {
			$content .= $this->msg( [ 'smw-es-replication-error-missing-id', $title_text, $id ] );
			$content .= '--LINE--';
			$content .= '<span style="font-size:12px;">' . $this->msg( 'smw-es-replication-error-suggestions' ) . '</span>';
		}

		return $content;
	}

	private function checkFileIngest( $subject ) {

		$config = $this->store->getConnection( 'elastic' )->getConfig();
		$content = '';

		$this->errorTitle = 'smw-es-replication-file-ingest-error';

		if ( $config->dotGet( 'indexer.experimental.file.ingest', false ) === false ) {
			return '';
		}

		$pv = $this->store->getPropertyValues(
			$subject,
			new DIProperty( '_FILE_ATTCH' )
		);

		if ( $pv === [] ) {
			$title = $subject->getTitle();
			$content .= $this->msg( [ 'smw-es-replication-error-file-ingest-missing-file-attachment', $title->getPrefixedText() ], Message::PARSE );
			$content .= '--LINE--';
			$content .= '<span style="font-size:12px;">' . $this->msg( 'smw-es-replication-error-file-ingest-missing-file-attachment-suggestions', Message::PARSE ) . '</span>';
		};

		return $content;
	}

	private function wrapHTML( $content ) {

		if ( $content === '' ) {
			return '';
		}

		if ( $this->isRTL ) {
			$line = '<div style="border-top: 1px solid #ebebeb;margin-top: 10px;margin-bottom: 8px;margin-right: -10px;width: 280px;"></div>';
		} else {
			$line = '<div style="border-top: 1px solid #ebebeb;margin-top: 10px;margin-bottom: 8px;margin-left: -10px;width: 280px;"></div>';
		}

		$content = str_replace( '--LINE--', $line, $content );

		$attribs = [
			'class' => 'smw-highlighter smw-icon-indicator-replication-error',
			'data-maxWidth' => '280',
			'data-state' => 'inline',
			'data-placement' => 'auto',
			'data-animation' => 'fade',
			'data-theme' => 'wide-popup',
			'data-title' => $this->msg( $this->errorTitle ),
			'data-content' => $content,
			'data-bottom' => '<span class="smw-issue-label" style="background-color: #cc317c;color: #ffffff;">elastic</span>'
		];

		return Html::rawElement( 'div', $attribs );
	}

	private function buildHTML( $title_text, $id, $isRTL = false, $dates = [] ) {

		if ( $isRTL ) {
			$line = '<div style="border-top: 1px solid #ebebeb;margin-top: 10px;margin-bottom: 8px;margin-right: -10px;width: 280px;"></div>';
		} else {
			$line = '<div style="border-top: 1px solid #ebebeb;margin-top: 10px;margin-bottom: 8px;margin-left: -10px;width: 280px;"></div>';
		}

		$content = '';
		$bottom = '<span class="smw-issue-label" style="background-color: #cc317c;color: #ffffff;">elastic</span>';

		if ( $dates !== [] ) {
			$content .= $this->msg( [ 'smw-es-replication-error-divergent-date', $title_text, $id ] );
			$content .= $line;
			$content .= $this->msg( [ 'smw-es-replication-error-divergent-date-detail', $dates['time_es'], $dates['time_store'] ], Message::PARSE );
			$content .= $line;
			$content .= '<span style="font-size:12px;">' . $this->msg( 'smw-es-replication-error-suggestions' ) . '</span>';
		} else {
			$content .= $this->msg( [ 'smw-es-replication-error-missing-id', $title_text, $id ] );
			$content .= $line;
			$content .= '<span style="font-size:12px;">' . $this->msg( 'smw-es-replication-error-suggestions' ) . '</span>';
		}

		return Html::rawElement(
			'div',
			[
				'class' => 'smw-highlighter smw-icon-indicator-replication-error',
				'data-maxWidth' => '280',
				'data-state' => 'inline',
				'data-placement' => 'auto',
				'data-animation' => 'fade',
				'data-theme' => 'wide-popup',
				'data-title' => $this->msg( 'smw-es-replication-error' ),
				'data-content' => $content,
				'data-bottom' => $bottom
			]
		);
	}

	private function msg( $key, $type = Message::TEXT, $lang = Message::USER_LANGUAGE ) {
		return Message::get( $key, $type, $lang );
	}

}
