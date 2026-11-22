<?php
/**
 * What an AI agent may read and write on a MyLinks page.
 *
 * Generated from the same field registry the editor uses, so agents get
 * exactly what the editor offers. Add-ons reach agents by registering fields
 * in that registry; a custom field type is mapped through the
 * `wp_mylinks_agent_field_schema` filter.
 *
 * @package    Wp_Mylinks
 * @subpackage Wp_Mylinks/includes/agent
 * @since      1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds field lists and JSON Schemas from the registry.
 */
final class Wp_Mylinks_Agent_Fields {

	/**
	 * Most rows a repeater may hold through an agent.
	 *
	 * @var int
	 */
	const MAX_ROWS = 200;

	/**
	 * Every field an agent can reach, keyed by agent key. A box or field with
	 * `'agent' => false` is out of reach entirely.
	 *
	 * @return array<string,array> Field args plus 'box_title'.
	 */
	public static function fields() {
		$fields = array();

		foreach ( Wp_Mylinks_Metabox::boxes() as $box ) {
			if ( ! in_array( 'mylink', (array) $box['object_types'], true ) || ( isset( $box['agent'] ) && false === $box['agent'] ) ) {
				continue;
			}
			foreach ( (array) $box['fields'] as $field ) {
				if ( empty( $field['id'] ) || ( isset( $field['agent'] ) && false === $field['agent'] ) ) {
					continue;
				}
				$field['box_title'] = (string) $box['title'];
				if ( null !== self::schema_for( $field ) ) {
					$fields[ self::key( $field['id'] ) ] = $field;
				}
			}
		}

		return $fields;
	}

	/**
	 * The agent-facing key of a meta key. Other prefixes pass through unchanged.
	 *
	 * @param string $meta_key Meta key.
	 * @return string
	 */
	public static function key( $meta_key ) {
		$meta_key = (string) $meta_key;
		return 0 === strpos( $meta_key, 'mylinks_' ) ? substr( $meta_key, 8 ) : $meta_key;
	}

	/**
	 * JSON Schema of one field.
	 *
	 * @param array $field Field args.
	 * @return array|null Null when the type has no agent mapping.
	 */
	public static function schema_for( array $field ) {
		$type   = isset( $field['type'] ) ? (string) $field['type'] : 'text';
		$schema = null;

		switch ( $type ) {
			case 'text':
				$schema = array(
					'type'      => 'string',
					'maxLength' => 500,
				);
				break;
			case 'textarea_small':
			case 'wysiwyg':
				$schema = array(
					'type'      => 'string',
					'maxLength' => 10000,
				);
				break;
			case 'text_url':
			case 'oembed':
				$protocols = ! empty( $field['protocols'] ) && is_array( $field['protocols'] ) ? $field['protocols'] : array( 'http', 'https' );
				$schema    = array(
					'type'      => 'string',
					'maxLength' => 2048,
					'pattern'   => '^$|^(?:' . implode( '|', array_map( 'preg_quote', $protocols ) ) . '):[^\s<>"]*$',
				);
				break;
			case 'select':
			case 'radio':
			case 'radio_inline':
				$control = Wp_Mylinks_Metabox::make_field( $field );
				$options = $control ? array_map( 'strval', array_keys( $control->options() ) ) : array();
				if ( $options ) {
					$schema = array(
						'type' => 'string',
						'enum' => $options,
					);
				}
				break;
			case 'colorpicker':
				$schema = array(
					'type'    => 'string',
					'pattern' => '^(#[0-9a-fA-F]{3}|#[0-9a-fA-F]{6})?$',
				);
				break;
			case 'file':
				$schema = array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => 'Media Library attachment ID. 0 clears it.',
				);
				break;
			case 'pw_select':
				$schema = array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => 'ID of a published post, page or link collection item. 0 clears it.',
				);
				break;
			case 'group':
				$schema = self::group_schema( $field );
				break;
		}

		/**
		 * Filters the JSON Schema an agent gets for a field.
		 *
		 * `$schema` is null for field types WP MyLinks does not map, and such
		 * a field stays out of reach unless a schema is returned here.
		 *
		 * @since 1.2.0
		 *
		 * @param array|null $schema JSON Schema, or null.
		 * @param array      $field  Field args from the registry.
		 */
		$schema = apply_filters( 'wp_mylinks_agent_field_schema', $schema, $field );

		if ( ! is_array( $schema ) ) {
			return null;
		}

		$box  = isset( $field['box_title'] ) ? (string) $field['box_title'] : '';
		$name = isset( $field['name'] ) && '' !== (string) $field['name'] ? (string) $field['name'] : $box;
		$help = isset( $schema['description'] ) ? (string) $schema['description'] : self::plain( isset( $field['desc'] ) ? $field['desc'] : '' );

		$schema['title']       = self::plain( $name );
		$schema['description'] = trim( $help . ( '' !== $box ? ' (' . self::plain( $box ) . ')' : '' ) );

		return $schema;
	}

	/**
	 * The subfields of a repeater that agents can reach, keyed by subfield id,
	 * each with its schema under '_schema'.
	 *
	 * @param array $group Group field args.
	 * @return array<string,array>
	 */
	public static function row_fields( array $group ) {
		$subs = array();

		foreach ( isset( $group['fields'] ) ? (array) $group['fields'] : array() as $sub ) {
			if ( empty( $sub['id'] ) || ( isset( $sub['agent'] ) && false === $sub['agent'] ) ) {
				continue;
			}
			$schema = self::schema_for( $sub );
			if ( null !== $schema ) {
				$sub['_schema']             = $schema;
				$subs[ (string) $sub['id'] ] = $sub;
			}
		}

		return $subs;
	}

	/**
	 * Schema of the `fields` input object.
	 *
	 * @return array
	 */
	public static function input_schema() {
		$properties = array();

		foreach ( self::fields() as $key => $field ) {
			$properties[ $key ] = self::schema_for( $field );
		}

		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		);
	}

	/**
	 * Every meta key that makes up a page's state.
	 *
	 * @return string[]
	 */
	public static function state_keys() {
		$keys = array();

		foreach ( self::fields() as $field ) {
			$keys[] = (string) $field['id'];
			if ( 'file' === ( isset( $field['type'] ) ? $field['type'] : '' ) ) {
				$keys[] = $field['id'] . '_id';
			}
		}

		return $keys;
	}

	/**
	 * Schema of a repeater.
	 *
	 * @param array $field Group field args.
	 * @return array
	 */
	private static function group_schema( array $field ) {
		$properties = array(
			'index' => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => 'Update mode: the existing row to change, counted from 0.',
			),
			'from'  => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => 'Replace mode: the existing row to carry over, counted from 0. Its settings are kept, and any field sent here changes it.',
			),
		);

		foreach ( self::row_fields( $field ) as $id => $sub ) {
			$properties[ $id ] = $sub['_schema'];
		}

		return array(
			'type'                 => 'object',
			'properties'           => array(
				'mode' => array(
					'type' => 'string',
					'enum' => array( 'update', 'append', 'replace' ),
				),
				'rows' => array(
					'type'     => 'array',
					'maxItems' => self::MAX_ROWS,
					'items'    => array(
						'type'                 => 'object',
						'properties'           => $properties,
						'additionalProperties' => false,
					),
				),
			),
			'required'             => array( 'mode', 'rows' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Field help as plain text in house style.
	 *
	 * @param mixed $text Label or help text, may hold HTML.
	 * @return string
	 */
	private static function plain( $text ) {
		return trim( str_replace( array( "\u{2014}", "\u{2013}" ), '-', wp_strip_all_tags( (string) $text ) ) );
	}
}
