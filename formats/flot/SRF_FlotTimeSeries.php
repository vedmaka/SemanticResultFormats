<?php

/**
 * A query printer for timeseries using the flot plotting JavaScript library
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @see http://www.semantic-mediawiki.org/wiki/Help:Flot_timeseries_chart
 *
 * @file SRF_FlotTimeseries.php
 * @ingroup SemanticResultFormats
 * @licence GNU GPL v2 or later
 *
 * @since 1.8
 *
 * @author mwjames
 */
class SRFFlotTimeseries extends SMWResultPrinter {

	/**
	 * @see SMWResultPrinter::getName
	 * @return string
	 */
	public function getName() {
		return wfMsg( 'srf-printername-timeseries' );
	}

	/**
	 * @see SMWResultPrinter::getResultText
	 *
	 * @param SMWQueryResult $result
	 * @param $outputMode
	 *
	 * @return string
	 */
	protected function getResultText( SMWQueryResult $result, $outputMode ) {

		// Data pre-processing check
		if ( $this->params['layout'] === '' ) {
			return $result->addErrors( array( wfMsgForContent( 'srf-error-missing-layout' ) ) );
		}

		// Data processing
		$data = $this->getAggregatedTimeSeries( $result, $outputMode );

		// Post-data processing check
		if ( count( $data ) == 0 ) {
			return $result->addErrors( array( wfMsgForContent( 'srf-warn-empy-chart' ) ) );
		} else {
			return $this->getFormatOutput( $data );
		}
	}

	/**
	 * Returns an array with numerical data
	 *
	 * @since 1.8
	 *
	 * @param SMWQueryResult $result
	 * @param $outputMode
	 *
	 * @return array
	 */
	protected function getAggregatedTimeSeries( SMWQueryResult $result, $outputMode ) {
		$values = array();
		$aggregatedValues = array ();

		while ( /* array of SMWResultArray */ $row = $result->getNext() ) { // Objects (pages)
			$timeStamp = '';
			$value     = '';
			$series = array();

			foreach ( $row as /* SMWResultArray */ $field ) {
				$value  = array();
				$sum    = array();
				$rowSum = array();

				// Group by subject (page object)  or property
				if ( $this->params['group'] == 'subject' ){
					$group = $field->getResultSubject()->getTitle()->getText();
				} else {
					$group = $field->getPrintRequest()->getLabel();
				}

				while ( ( /* SMWDataValue */ $dataValue = $field->getNextDataValue() ) !== false ) { // Data values

					// Find the timestamp
					if ( $dataValue->getDataItem()->getDIType() == SMWDataItem::TYPE_TIME ){
						// We work with a timestamp, we have to use intval because DataItem
						// returns a string but we want a numeric representation of the timestamp
						$timeStamp = intval( $dataValue->getDataItem()->getMwTimestamp() );
					}

					// Find the values (numbers only)
					if ( $dataValue->getDataItem()->getDIType() == SMWDataItem::TYPE_NUMBER ){
						$sum[] = $dataValue->getNumber();
					}
				}
				// Aggegate individual values into a sum
				$rowSum = array_sum( $sum );

				// Check the sum and threshold/min
				if ( $timeStamp !== '' && $rowSum == true && $rowSum >= $this->params['min'] ) {
					$series[$group] = array ( $timeStamp , $rowSum ) ;
				}
			}
				$values[] = $series ;
		}

		// Re-assign values according to their group
		foreach ( $values as $key => $value ) {
			foreach ( $values[$key] as $row => $rowvalue ) {
					$aggregatedValues[$row][] = $rowvalue;
			}
		}
		return $aggregatedValues;
	}

	/**
	 * Prepare data for the output
	 *
	 * @since 1.8
	 *
	 * @param array $data
	 *
	 * @return string
	 */
	protected function getFormatOutput( array $data ) {

		// Object count
		static $statNr = 0;
		$chartID = 'flot-timeseries-' . ++$statNr;

		$this->isHTML = true;

		// Reorganize the raw data
		foreach ( $data as $key => $values ) {
			$dataObject[] = array ( 'label' => $key, 'data' => $values );
		}

		// Prepare transfer array
		$chartData = array (
			'data' => $dataObject,
			'parameters' => array (
				'width'       => $this->params['width'],
				'height'      => $this->params['height'],
				'charttitle'  => $this->params['charttitle'],
				'charttext'   => $this->params['charttext'],
				'layout'      => $this->params['layout'],
				'datatable'   => $this->params['tablearea'],
				'zoom'        => $this->params['zoomarea'],
			)
		);

		// Array encoding and output
		$requireHeadItem = array ( $chartID => FormatJson::encode( $chartData ) );
		SMWOutputs::requireHeadItem( $chartID, Skin::makeVariablesScript( $requireHeadItem ) );

		// RL module
		SMWOutputs::requireResource( 'ext.srf.flot.timeseries' );

		// Chart/graph placeholder
		$chart = Html::rawElement( 'div', array(
			'id' => $chartID,
			'class' => 'container',
			'style' => "display:none;"
			), null
		);

		// Processing/loading image
		$processing = SRFUtils::htmlProcessingElement( $this->isHTML );

		// Beautify class selector
		$class = $this->params['class'] ? ' ' . $this->params['class'] : ' flot-chart-common';

		// General output marker
		return Html::rawElement( 'div', array(
			'class' => 'srf-flot-timeseries' . $class
			), $processing . $chart
		);
	}

	/**
	 * @see SMWResultPrinter::getParamDefinitions
	 *
	 * @since 1.8
	 *
	 * @param $definitions array of IParamDefinition
	 *
	 * @return array of IParamDefinition|array
	 */
	public function getParamDefinitions( array $definitions ) {
		$params = parent::getParamDefinitions( $definitions );

		$params['layout'] = array(
			'message' => 'srf-paramdesc-layout',
			'default' => 'line',
			'values' => array( 'line', 'bar'),
		);

		$params['min'] = array(
			'type' => 'integer',
			'message' => 'srf-paramdesc-minvalue',
			'default' => '',
			'values' => array( 'line', 'bar'),
		);

		$params['group'] = array(
			'message' => 'srf-paramdesc-group',
			'default' => 'subject',
			'values' => array( 'property' , 'subject' ),
		);

		$params['zoomarea'] = array(
			'message' => 'srf-paramdesc-zoomarea',
			'default' => 'bottom',
			'values' => array( 'none' , 'bottom', 'top' ),
		);

		$params['tablearea'] = array(
			'message' => 'srf-paramdesc-tablearea',
			'default' => 'bottom',
			'values' => array( 'none' , 'bottom', 'top' ),
		);

		$params['height'] = array(
			'type' => 'integer',
			'message' => 'srf_paramdesc_chartheight',
			'default' => 400,
			'lowerbound' => 1,
		);

		$params['width'] = array(
			'type' => 'integer',
			'message' => 'srf_paramdesc_chartwidth',
			'default' => 400,
			'lowerbound' => 1,
		);

		$params['charttitle'] = array(
			'message' => 'srf_paramdesc_charttitle',
			'default' => '',
		);

		$params['charttext'] = array(
			'message' => 'srf-paramdesc-charttext',
			'default' => '',
		);

		$params['class'] = array(
			'message' => 'srf-paramdesc-class',
			'default' => '',
		);

		return $params;
	}
}