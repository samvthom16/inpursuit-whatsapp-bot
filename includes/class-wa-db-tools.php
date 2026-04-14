<?php
defined( 'ABSPATH' ) || exit;

/**
 * Database query tools for the AI Agent.
 * Each method enforces group access and returns a raw array for the AI to process.
 * Group filtering is always enforced in PHP — the AI cannot bypass it.
 */
class INPURSUIT_WA_DB_Tools {

    // -------------------------------------------------------------------------
    // Tools
    // -------------------------------------------------------------------------

    /**
     * Tool: get_member_details
     * Full profile for a single member by name (with group access check).
     */
    public static function get_member_details( $params, $wp_user = null ) {
        global $wpdb;

        $name = sanitize_text_field( $params['name'] ?? '' );
        if ( empty( $name ) ) {
            return array( 'error' => 'name parameter is required.' );
        }

        $member = self::resolve_single_member( $name, $wp_user );
        if ( isset( $member['error'] ) ) {
            return $member;
        }

        $id       = $member['id'];
        $dates_db = INPURSUIT_DB_MEMBER_DATES::getInstance();

        $comment_table = $wpdb->prefix . 'ip_comments';
        $recent_notes  = $wpdb->get_results( $wpdb->prepare(
            "SELECT comment, created_at FROM {$comment_table}
             WHERE post_id = %d ORDER BY created_at DESC LIMIT 5",
            $id
        ) );

        return array(
            'name'         => $member['name'],
            'status'       => self::get_term( $id, 'inpursuit-status' ),
            'group'        => self::get_term( $id, 'inpursuit-group' ),
            'gender'       => self::get_term( $id, 'inpursuit-gender' ),
            'profession'   => self::get_term( $id, 'inpursuit-profession' ),
            'location'     => self::get_term( $id, 'inpursuit-location' ),
            'age'          => $dates_db->age( $id ),
            'last_seen'    => self::get_last_seen( $id ),
            'recent_notes' => array_map( function( $c ) {
                return array( 'text' => $c->comment, 'date' => $c->created_at );
            }, $recent_notes ),
        );
    }

    /**
     * Tool: get_events
     * Birthdays and anniversaries remaining this month.
     */
    public static function get_events( $params, $wp_user = null ) {
        global $wpdb;

        $table     = $wpdb->prefix . 'ip_member_dates';
        $month     = (int) date( 'm' );
        $today_day = (int) date( 'd' );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT md.member_id, md.event_type, md.event_date, p.post_title AS member_name
             FROM {$table} md
             INNER JOIN {$wpdb->posts} p ON p.ID = md.member_id
             WHERE p.post_status = 'publish' AND p.post_type = %s
               AND MONTH( md.event_date ) = %d AND DAY( md.event_date ) >= %d
             ORDER BY DAY( md.event_date ) ASC",
            INPURSUIT_MEMBERS_POST_TYPE, $month, $today_day
        ) );

        $user_ids = self::get_user_group_ids( $wp_user );
        $result   = array();
        foreach ( $rows as $row ) {
            if ( ! empty( $user_ids ) ) {
                $member_groups = wp_get_post_terms( $row->member_id, 'inpursuit-group', array( 'fields' => 'ids' ) );
                if ( empty( array_intersect( $user_ids, $member_groups ) ) ) {
                    continue;
                }
            }
            $result[] = array(
                'member'     => $row->member_name,
                'event_type' => ( stripos( $row->event_type, 'birth' ) !== false ) ? 'Birthday' : 'Anniversary',
                'date'       => date( 'd M', strtotime( $row->event_date ) ),
            );
        }

        return array( 'month' => date( 'F Y' ), 'count' => count( $result ), 'events' => $result );
    }

    /**
     * Tool: add_member_comment
     * Save a comment (with optional category). Group access enforced.
     */
    public static function add_member_comment( $params, $wp_user = null ) {
        global $wpdb;

        $member_name   = sanitize_text_field( $params['member_name'] ?? '' );
        $comment_text  = sanitize_textarea_field( $params['comment_text'] ?? '' );
        $category_name = sanitize_text_field( $params['category_name'] ?? '' );

        if ( empty( $member_name ) || empty( $comment_text ) ) {
            return array( 'error' => 'member_name and comment_text are required.' );
        }

        $member = self::resolve_single_member( $member_name, $wp_user );
        if ( isset( $member['error'] ) ) {
            return $member;
        }

        $comment_db = INPURSUIT_DB_COMMENT::getInstance();
        $comment_id = $comment_db->insert( array(
            'comment' => $comment_text,
            'post_id' => (int) $member['id'],
            'user_id' => $wp_user ? (int) $wp_user->ID : 0,
        ) );

        if ( ! $comment_id ) {
            return array( 'error' => 'Failed to save comment.' );
        }

        $category_saved = null;
        if ( ! empty( $category_name ) ) {
            $cat_db    = INPURSUIT_DB_COMMENTS_CATEGORY::getInstance();
            $cat_table = $cat_db->getTable();
            $cat_row = $wpdb->get_row( $wpdb->prepare(
                "SELECT term_id, name FROM {$cat_table} WHERE LOWER(name) = LOWER(%s) LIMIT 1",
                $category_name
            ) );
            if ( ! $cat_row ) {
                $like    = '%' . $wpdb->esc_like( $category_name ) . '%';
                $cat_row = $wpdb->get_row( $wpdb->prepare(
                    "SELECT term_id, name FROM {$cat_table} WHERE name LIKE %s LIMIT 1",
                    $like
                ) );
            }
            if ( $cat_row ) {
                INPURSUIT_DB_COMMENTS_CATEGORY_RELATION::getInstance()->insert( array(
                    'term_id'    => (int) $cat_row->term_id,
                    'comment_id' => (int) $comment_id,
                ) );
                $category_saved = $cat_row->name;
            }
        }

        return array(
            'success'  => true,
            'member'   => $member['name'],
            'category' => $category_saved,
        );
    }

    /**
     * Tool: get_comment_categories
     * List all available comment categories.
     */
    public static function get_comment_categories( $params, $wp_user = null ) {
        $cat_db = INPURSUIT_DB_COMMENTS_CATEGORY::getInstance();
        $rows   = $cat_db->get_results( $cat_db->getResultsQuery( array() ) );

        return array(
            'categories' => array_map( function( $r ) {
                return array( 'id' => $r->term_id, 'name' => $r->name );
            }, (array) $rows ),
        );
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private static function get_user_group_ids( $wp_user ) {
        if ( ! $wp_user ) {
            return array();
        }
        $ids = get_user_meta( $wp_user->ID, 'inpursuit-group', true );
        return ( is_array( $ids ) && ! empty( $ids ) ) ? array_map( 'intval', $ids ) : array();
    }

    private static function group_tax_query( $wp_user ) {
        $ids = self::get_user_group_ids( $wp_user );
        if ( empty( $ids ) ) {
            return array();
        }
        return array(
            array(
                'taxonomy' => 'inpursuit-group',
                'field'    => 'id',
                'terms'    => $ids,
            ),
        );
    }

    private static function resolve_single_member( $name, $wp_user ) {
        global $wpdb;

        $like     = '%' . $wpdb->esc_like( $name ) . '%';
        $user_ids = self::get_user_group_ids( $wp_user );

        $tax_join  = '';
        $tax_where = '';
        if ( ! empty( $user_ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
            $tax_join     = "INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
                             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'inpursuit-group'";
            $tax_where    = $wpdb->prepare( " AND tt.term_id IN ($placeholders)", $user_ids );
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT p.ID, p.post_title FROM {$wpdb->posts} p $tax_join
             WHERE p.post_type = %s AND p.post_status = 'publish' AND p.post_title LIKE %s $tax_where
             LIMIT 5",
            INPURSUIT_MEMBERS_POST_TYPE, $like
        ) );

        if ( empty( $rows ) ) {
            return array( 'error' => "No member found matching \"{$name}\"." );
        }
        if ( count( $rows ) > 1 ) {
            return array(
                'error'   => 'Multiple members found. Be more specific.',
                'matches' => wp_list_pluck( $rows, 'post_title' ),
            );
        }

        return array( 'id' => $rows[0]->ID, 'name' => $rows[0]->post_title );
    }

    private static function get_term( $post_id, $taxonomy ) {
        $terms = get_the_terms( $post_id, $taxonomy );
        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return null;
        }
        return $terms[0]->name;
    }

    private static function get_last_seen( $member_id ) {
        global $wpdb;
        $table    = $wpdb->prefix . 'ip_event_member_relation';
        $event_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT r.event_id FROM {$table} r
             INNER JOIN {$wpdb->posts} p ON p.ID = r.event_id
             WHERE r.member_id = %d AND p.post_status = 'publish'
             ORDER BY p.post_date DESC LIMIT 1",
            $member_id
        ) );
        if ( ! $event_id ) {
            return null;
        }
        return get_the_date( 'd M Y', $event_id ) . ' — ' . get_the_title( $event_id );
    }
}
