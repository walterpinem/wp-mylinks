<?php
/**
 * A MyLinks page's state as agents see and change it.
 *
 * Reads the page, merges an agent's changes through the editor's own field
 * sanitizers, and writes the result. Nothing here checks who is calling: the
 * abilities do that first.
 *
 * @package    Wp_Mylinks
 * @subpackage Wp_Mylinks/includes/agent
 * @since      1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Page state: read, version, merge, write, output.
 */
final class Wp_Mylinks_Agent_State {

	/**
	 * The page's title, slug and every registry meta value that exists.
	 * Repeaters come back as stored, keys the registry does not know included.
	 *
	 * @param WP_Post $post Page.
	 * @return array{title:string,slug:string,meta:array}
	 */
	public static function read( WP_Post $post ) {
		$meta = array();

		foreach ( Wp_Mylinks_Agent_Fields::state_keys() as $key ) {
			if ( metadata_exists( 'post', $post->ID, $key ) ) {
				$meta[ $key ] = get_post_meta( $post->ID, $key, true );
			}
		}

		return array(
			'title' => (string) $post->post_title,
			'slug'  => (string) $post->post_name,
			'meta'  => $meta,
		);
	}

	/**
	 * Short fingerprint of a state. It covers meta-only writers such as a
	 * visual builder, which never touch post_modified.
	 *
	 * @param array $state State from read().
	 * @return string 12 hex characters.
	 */
	public static function version( array $state ) {
		return substr( md5( serialize( $state ) ), 0, 12 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- A fingerprint, never unserialized.
	}

	/**
	 * Apply changes to a state, without saving anything.
	 *
	 * HTML is always cleaned: `unfiltered_html` is denied for the whole merge,
	 * even to administrators and network super admins (whose capability checks
	 * skip `user_has_cap`, hence `map_meta_cap`).
	 *
	 * @param array $state   State from read().
	 * @param array $changes {title?, slug?, fields?}.
	 * @return array{state:array,changed:string[]}|WP_Error
	 */
	public static function merge( array $state, array $changes ) {
		$deny = static function ( $caps, $cap ) {
			return 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps;
		};

		add_filter( 'map_meta_cap', $deny, PHP_INT_MAX, 2 );
		try {
			return self::apply( $state, $changes );
		} finally {
			remove_filter( 'map_meta_cap', $deny, PHP_INT_MAX );
		}
	}

	/**
	 * Save a state to the page. Only what differs is written.
	 *
	 * @param WP_Post $post  Page.
	 * @param array   $state State to store.
	 * @return true|WP_Error
	 */
	public static function write( WP_Post $post, array $state ) {
		$current = self::read( $post );

		foreach ( $state['meta'] as $key => $value ) {
			if ( ! array_key_exists( $key, $current['meta'] ) || $current['meta'][ $key ] !== $value ) {
				update_post_meta( $post->ID, $key, wp_slash( $value ) );
			}
		}
		foreach ( array_keys( array_diff_key( $current['meta'], $state['meta'] ) ) as $key ) {
			delete_post_meta( $post->ID, $key );
		}

		$update = array();
		if ( $state['title'] !== $current['title'] ) {
			$update['post_title'] = wp_slash( $state['title'] );
		}
		if ( $state['slug'] !== $current['slug'] ) {
			$update['post_name'] = $state['slug'];
		}
		if ( $update ) {
			$update['ID'] = $post->ID;
			$result       = wp_update_post( $update, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * The agent's view of a state's fields.
	 *
	 * @param array $state State from read().
	 * @return array|object Agent key => value; an empty object when no fields.
	 */
	public static function to_output( array $state ) {
		$out = array();

		foreach ( Wp_Mylinks_Agent_Fields::fields() as $key => $field ) {
			$id   = (string) $field['id'];
			$type = isset( $field['type'] ) ? (string) $field['type'] : 'text';

			if ( 'group' === $type ) {
				$out[ $key ] = self::rows_output( $field, isset( $state['meta'][ $id ] ) ? $state['meta'][ $id ] : array() );
			} elseif ( 'file' === $type ) {
				$out[ $key ] = array(
					'attachment_id' => isset( $state['meta'][ $id . '_id' ] ) ? (int) $state['meta'][ $id . '_id' ] : 0,
					'url'           => isset( $state['meta'][ $id ] ) ? (string) $state['meta'][ $id ] : '',
				);
			} elseif ( array_key_exists( $id, $state['meta'] ) ) {
				$out[ $key ] = $state['meta'][ $id ];
			} else {
				$out[ $key ] = isset( $field['default'] ) ? $field['default'] : '';
			}
		}

		return $out ? $out : (object) array();
	}

	/**
	 * Does a stored row hold anything the current schema cannot express?
	 *
	 * @param array $row  Stored row.
	 * @param array $subs Subfields from Wp_Mylinks_Agent_Fields::row_fields().
	 * @return bool
	 */
	public static function row_has_inactive( array $row, array $subs ) {
		foreach ( $row as $key => $value ) {
			if ( Wp_Mylinks_Field::is_empty_value( $value ) ) {
				continue;
			}
			$base = '_id' === substr( (string) $key, -3 ) ? substr( (string) $key, 0, -3 ) : '';
			if ( '' !== $base && isset( $subs[ $base ] ) && 'file' === $subs[ $base ]['type'] ) {
				continue;
			}
			if ( ! isset( $subs[ $key ] ) ) {
				return true;
			}
			if ( isset( $subs[ $key ]['_schema']['enum'] ) && ! in_array( (string) $value, $subs[ $key ]['_schema']['enum'], true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The merge itself.
	 *
	 * @param array $state   State.
	 * @param array $changes Changes.
	 * @return array{state:array,changed:string[]}|WP_Error
	 */
	private static function apply( array $state, array $changes ) {
		$changed = array();

		if ( array_key_exists( 'title', $changes ) ) {
			$title = is_string( $changes['title'] ) ? sanitize_text_field( $changes['title'] ) : '';
			if ( '' === $title ) {
				return new WP_Error( 'wp_mylinks_invalid_title', __( 'The title cannot be empty.', 'wp-mylinks' ), array( 'status' => 400 ) );
			}
			if ( $title !== $state['title'] ) {
				$state['title'] = $title;
				$changed[]      = 'title';
			}
		}

		if ( array_key_exists( 'slug', $changes ) ) {
			$slug = is_string( $changes['slug'] ) ? sanitize_title( $changes['slug'] ) : '';
			if ( '' === $slug ) {
				return new WP_Error( 'wp_mylinks_invalid_slug', __( 'The slug cannot be empty.', 'wp-mylinks' ), array( 'status' => 400 ) );
			}
			if ( $slug !== $state['slug'] ) {
				$state['slug'] = $slug;
				$changed[]     = 'slug';
			}
		}

		$registry = Wp_Mylinks_Agent_Fields::fields();

		foreach ( isset( $changes['fields'] ) ? (array) $changes['fields'] : array() as $key => $raw ) {
			if ( ! isset( $registry[ $key ] ) ) {
				/* translators: %s: field key */
				return new WP_Error( 'wp_mylinks_unknown_field', sprintf( __( 'Unknown field: %s.', 'wp-mylinks' ), $key ), array( 'status' => 400 ) );
			}

			$field  = $registry[ $key ];
			$id     = (string) $field['id'];
			$schema = Wp_Mylinks_Agent_Fields::schema_for( $field );
			$valid  = rest_validate_value_from_schema( $raw, $schema, (string) $key );
			if ( is_wp_error( $valid ) ) {
				return new WP_Error( 'wp_mylinks_invalid_field', $valid->get_error_message(), array( 'status' => 400 ) );
			}
			$raw    = rest_sanitize_value_from_schema( $raw, $schema, (string) $key );
			$before = $state['meta'];

			if ( 'group' === $field['type'] ) {
				$rows = self::merge_group( $field, isset( $state['meta'][ $id ] ) ? $state['meta'][ $id ] : array(), $raw );
				if ( is_wp_error( $rows ) ) {
					return $rows;
				}
				self::put( $state['meta'], $id, $rows );
			} elseif ( 'file' === $field['type'] ) {
				$pair = self::attachment( $field, $raw );
				if ( is_wp_error( $pair ) ) {
					return $pair;
				}
				self::put( $state['meta'], $id, $pair ? $pair['url'] : '' );
				self::put( $state['meta'], $id . '_id', $pair ? $pair['id'] : '' );
			} else {
				$value = self::clean( $field, $raw );
				if ( is_wp_error( $value ) ) {
					return $value;
				}
				self::put( $state['meta'], $id, $value );
			}

			if ( $before !== $state['meta'] ) {
				$changed[] = (string) $key;
			}
		}

		return array(
			'state'   => $state,
			'changed' => $changed,
		);
	}

	/**
	 * Set a meta value, or remove it when empty (the editor deletes empty rows).
	 *
	 * @param array  $meta  Meta array.
	 * @param string $key   Meta key.
	 * @param mixed  $value Value.
	 * @return void
	 */
	private static function put( array &$meta, $key, $value ) {
		if ( Wp_Mylinks_Field::is_empty_value( $value ) ) {
			unset( $meta[ $key ] );
		} else {
			$meta[ $key ] = $value;
		}
	}

	/**
	 * Run one value through the field's own sanitizer, with the editor's slash
	 * contract.
	 *
	 * @param array $field Field args.
	 * @param mixed $raw   Value.
	 * @return mixed|WP_Error
	 */
	private static function clean( array $field, $raw ) {
		if ( 'pw_select' === $field['type'] ) {
			$raw = (int) $raw;
			if ( 0 === $raw ) {
				return '';
			}
			$target = get_post( $raw );
			if ( ! $target instanceof WP_Post
				|| 'publish' !== $target->post_status
				|| ! in_array( $target->post_type, wp_mylinks_get_link_post_types( true ), true )
				|| ! current_user_can( 'read_post', $target->ID ) ) {
				return new WP_Error( 'wp_mylinks_invalid_link_target', __( 'That post is not a published item this page can link to.', 'wp-mylinks' ), array( 'status' => 400 ) );
			}
			$raw = (string) $raw;
		}

		$control = Wp_Mylinks_Metabox::make_field( $field );
		return $control ? wp_unslash( $control->sanitize( wp_slash( $raw ) ) ) : '';
	}

	/**
	 * Resolve an attachment ID for a file field.
	 *
	 * @param array $field Field args.
	 * @param mixed $raw   Attachment ID; 0 clears.
	 * @return array{id:int,url:string}|null|WP_Error
	 */
	private static function attachment( array $field, $raw ) {
		$id = (int) $raw;
		if ( 0 === $id ) {
			return null;
		}

		$url = wp_attachment_is_image( $id ) && current_user_can( 'read_post', $id ) ? wp_get_attachment_url( $id ) : '';
		$url = $url ? wp_unslash( Wp_Mylinks_Metabox::make_field( $field )->sanitize( wp_slash( $url ) ) ) : '';
		if ( ! $url ) {
			/* translators: %d: attachment ID */
			return new WP_Error( 'wp_mylinks_invalid_image', sprintf( __( 'Attachment %d is not an image in the Media Library.', 'wp-mylinks' ), $id ), array( 'status' => 400 ) );
		}

		return array(
			'id'  => $id,
			'url' => (string) $url,
		);
	}

	/**
	 * Merge a repeater per key, so unsent and unknown keys survive.
	 *
	 * @param array $field  Group field args.
	 * @param mixed $stored Stored rows.
	 * @param array $input  {mode, rows}.
	 * @return array|WP_Error Rows, reindexed from 0.
	 */
	private static function merge_group( array $field, $stored, array $input ) {
		$stored = array_values( array_filter( is_array( $stored ) ? $stored : array(), 'is_array' ) );
		$subs   = Wp_Mylinks_Agent_Fields::row_fields( $field );
		$mode   = $input['mode'];
		$result = 'replace' === $mode ? array() : $stored;
		$used   = array();

		foreach ( array_values( $input['rows'] ) as $i => $row ) {
			$number = $i + 1;
			$base   = array();
			$target = null;

			if ( ( 'update' !== $mode && isset( $row['index'] ) ) || ( 'replace' !== $mode && isset( $row['from'] ) ) ) {
				return self::row_error( $number, __( 'index is only for update mode, and from only for replace mode.', 'wp-mylinks' ) );
			}
			if ( 'update' === $mode && ! isset( $row['index'] ) ) {
				return self::row_error( $number, __( 'update mode needs the index of the row to change.', 'wp-mylinks' ) );
			}

			$source = 'update' === $mode ? $row['index'] : ( isset( $row['from'] ) ? $row['from'] : null );
			if ( null !== $source ) {
				$source = (int) $source;
				if ( ! isset( $stored[ $source ] ) ) {
					/* translators: %d: row index */
					return self::row_error( $number, sprintf( __( 'there is no existing row at index %d.', 'wp-mylinks' ), $source ) );
				}
				if ( isset( $used[ $source ] ) ) {
					/* translators: %d: row index */
					return self::row_error( $number, sprintf( __( 'existing row %d is used twice.', 'wp-mylinks' ), $source ) );
				}
				$used[ $source ] = true;
				$base            = $stored[ $source ];
				$target          = 'update' === $mode ? $source : null;
			}

			$merged = self::merge_row( $base, $row, $field, $subs, $number );
			if ( is_wp_error( $merged ) ) {
				return $merged;
			}

			if ( null !== $target ) {
				$result[ $target ] = $merged;
			} else {
				$result[] = $merged;
			}
		}

		if ( 'replace' === $mode ) {
			foreach ( $stored as $index => $row ) {
				if ( ! isset( $used[ $index ] ) && self::row_has_inactive( $row, $subs ) ) {
					return new WP_Error(
						'wp_mylinks_rows_inactive_settings',
						sprintf(
							/* translators: %d: row index */
							__( 'Existing row %d holds settings that are not available right now, and this replace would delete them. Carry it over with "from", or use update or append.', 'wp-mylinks' ),
							$index
						),
						array( 'status' => 409 )
					);
				}
			}
		}

		$result = array_values(
			array_filter(
				$result,
				static function ( $row ) {
					return array() !== $row;
				}
			)
		);

		if ( 'update' !== $mode && count( $result ) > Wp_Mylinks_Agent_Fields::MAX_ROWS ) {
			/* translators: %d: maximum number of rows */
			return new WP_Error( 'wp_mylinks_too_many_rows', sprintf( __( 'A list can hold at most %d rows.', 'wp-mylinks' ), Wp_Mylinks_Agent_Fields::MAX_ROWS ), array( 'status' => 400 ) );
		}

		return $result;
	}

	/**
	 * Apply one input row onto a stored row.
	 *
	 * @param array $row    Stored row, or empty for a new one.
	 * @param array $input  Input row.
	 * @param array $field  Group field args.
	 * @param array $subs   Reachable subfields.
	 * @param int   $number Row number for errors, from 1.
	 * @return array|WP_Error
	 */
	private static function merge_row( array $row, array $input, array $field, array $subs, $number ) {
		$values = array_diff_key( $input, array_flip( array( 'index', 'from' ) ) );
		if ( ! $values ) {
			return $row;
		}

		foreach ( $values as $key => $raw ) {
			if ( ! isset( $subs[ $key ] ) ) {
				/* translators: %s: field key */
				return self::row_error( $number, sprintf( __( 'unknown field %s.', 'wp-mylinks' ), $key ) );
			}

			if ( 'file' === $subs[ $key ]['type'] ) {
				$pair = self::attachment( $subs[ $key ], $raw );
				if ( is_wp_error( $pair ) ) {
					return self::row_error( $number, $pair->get_error_message() );
				}
				self::put( $row, $key . '_id', $pair ? $pair['id'] : '' );
				self::put( $row, $key, $pair ? $pair['url'] : '' );
				continue;
			}

			$value = self::clean( $subs[ $key ], $raw );
			if ( is_wp_error( $value ) ) {
				return self::row_error( $number, $value->get_error_message() );
			}
			self::put( $row, $key, $value );
		}

		// The page resolves a picked post before the typed URL, so a new URL
		// must clear the old pick or it would never show.
		if ( array_key_exists( 'url', $values ) && ! array_key_exists( 'select_url', $values ) ) {
			unset( $row['select_url'] );
		}

		// Registry order first, as the editor stores it; unknown keys after.
		$ordered = array();
		foreach ( isset( $field['fields'] ) ? (array) $field['fields'] : array() as $sub ) {
			$id = isset( $sub['id'] ) ? (string) $sub['id'] : '';
			if ( isset( $sub['type'] ) && 'file' === $sub['type'] && array_key_exists( $id . '_id', $row ) ) {
				$ordered[ $id . '_id' ] = $row[ $id . '_id' ];
			}
			if ( array_key_exists( $id, $row ) ) {
				$ordered[ $id ] = $row[ $id ];
			}
		}

		return $ordered + $row;
	}

	/**
	 * An error naming the input row, counted from 1.
	 *
	 * @param int    $number  Row number.
	 * @param string $message Message.
	 * @return WP_Error
	 */
	private static function row_error( $number, $message ) {
		return new WP_Error(
			'wp_mylinks_invalid_row',
			/* translators: 1: row number, 2: what is wrong */
			sprintf( __( 'Row %1$d: %2$s', 'wp-mylinks' ), $number, $message ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Stored rows as the agent sees them.
	 *
	 * @param array $field  Group field args.
	 * @param mixed $stored Stored rows.
	 * @return array[]
	 */
	private static function rows_output( array $field, $stored ) {
		$subs = Wp_Mylinks_Agent_Fields::row_fields( $field );
		$rows = array();

		foreach ( array_values( array_filter( is_array( $stored ) ? $stored : array(), 'is_array' ) ) as $index => $row ) {
			$out = array( 'index' => $index );

			foreach ( $subs as $id => $sub ) {
				if ( 'file' === $sub['type'] ) {
					if ( ! empty( $row[ $id ] ) ) {
						$out[ $id ] = array(
							'attachment_id' => isset( $row[ $id . '_id' ] ) ? (int) $row[ $id . '_id' ] : 0,
							'url'           => (string) $row[ $id ],
						);
					}
				} elseif ( 'pw_select' === $sub['type'] ) {
					if ( ! empty( $row[ $id ] ) ) {
						$out[ $id ] = (int) $row[ $id ];
					}
				} elseif ( isset( $row[ $id ] ) && ! Wp_Mylinks_Field::is_empty_value( $row[ $id ] ) ) {
					$out[ $id ] = $row[ $id ];
				} elseif ( isset( $sub['default'] ) && '' !== $sub['default'] ) {
					$out[ $id ] = $sub['default'];
				}
			}

			$out['has_inactive_settings'] = self::row_has_inactive( $row, $subs );
			$rows[]                       = $out;
		}

		return $rows;
	}
}
