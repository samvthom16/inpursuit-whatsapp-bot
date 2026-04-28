<?php
defined( 'ABSPATH' ) || exit;

/**
 * Database query tools for the AI Agent.
 * All data access goes through the parent plugin's DB helper classes.
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
        $name = sanitize_text_field( $params['name'] ?? '' );
        if ( empty( $name ) ) {
            return array( 'error' => 'name parameter is required.' );
        }

        $member = self::resolve_single_member( $name, $wp_user );
        if ( isset( $member['error'] ) ) {
            return $member;
        }

        $id          = $member['id'];
        $dates_db    = INPURSUIT_DB_MEMBER_DATES::getInstance();
        $comment_db  = INPURSUIT_DB_COMMENT::getInstance();

        $notes_query  = $comment_db->getResultsQuery( array( 'member_id' => $id ) ) . ' ORDER BY ID DESC LIMIT 5';
        $recent_rows  = $comment_db->get_results( $notes_query );

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
                return array( 'text' => $c->text, 'date' => $c->post_date );
            }, $recent_rows ),
        );
    }

    /**
     * Tool: get_events
     * Birthdays and anniversaries coming up in the next 30 days.
     * Uses the parent plugin's getNextOneMonthEvents() method.
     */
    public static function get_events( $params, $wp_user = null ) {
        $dates_db = INPURSUIT_DB_MEMBER_DATES::getInstance();
        $response = $dates_db->getNextOneMonthEvents( array( 'page' => 1, 'per_page' => 200 ) );
        $events   = $response->get_data();

        $user_ids = self::get_user_group_ids( $wp_user );
        $result   = array();

        foreach ( $events as $event ) {
            if ( ! empty( $user_ids ) ) {
                $member_groups = wp_get_post_terms( $event['member_id'], 'inpursuit-group', array( 'fields' => 'ids' ) );
                if ( empty( array_intersect( $user_ids, $member_groups ) ) ) {
                    continue;
                }
            }
            $result[] = array(
                'member'     => $event['member_name'],
                'event_type' => ( stripos( $event['event_type'], 'birth' ) !== false ) ? 'Birthday' : 'Anniversary',
                'date'       => date( 'd M', strtotime( $event['event_date'] ) ),
            );
        }

        return array( 'count' => count( $result ), 'events' => $result );
    }

    /**
     * Tool: add_member_comment
     * Save a comment (with optional category). Group access enforced.
     */
    public static function add_member_comment( $params, $wp_user = null ) {
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
            $all_cats  = $cat_db->generate_settings_schema(); // [ term_id => name ]

            $matched_id   = null;
            $matched_name = null;

            // Exact match (case-insensitive)
            foreach ( $all_cats as $term_id => $name ) {
                if ( strtolower( $name ) === strtolower( $category_name ) ) {
                    $matched_id   = $term_id;
                    $matched_name = $name;
                    break;
                }
            }

            // Partial match fallback
            if ( null === $matched_id ) {
                foreach ( $all_cats as $term_id => $name ) {
                    if ( stripos( $name, $category_name ) !== false ) {
                        $matched_id   = $term_id;
                        $matched_name = $name;
                        break;
                    }
                }
            }

            if ( null !== $matched_id ) {
                INPURSUIT_DB_COMMENTS_CATEGORY_RELATION::getInstance()->insert( array(
                    'term_id'    => (int) $matched_id,
                    'comment_id' => (int) $comment_id,
                ) );
                $category_saved = $matched_name;
            }
        }

        return array(
            'success'  => true,
            'member'   => $member['name'],
            'category' => $category_saved,
        );
    }

    /**
     * Tool: get_member_comments
     * All follow-up notes for a member, newest first.
     */
    public static function get_member_comments( $params, $wp_user = null ) {
        $name = sanitize_text_field( $params['name'] ?? '' );
        if ( empty( $name ) ) {
            return array( 'error' => 'name parameter is required.' );
        }

        $member = self::resolve_single_member( $name, $wp_user );
        if ( isset( $member['error'] ) ) {
            return $member;
        }

        $comment_db  = INPURSUIT_DB_COMMENT::getInstance();
        $relation_db = INPURSUIT_DB_COMMENTS_CATEGORY_RELATION::getInstance();
        $cat_db      = INPURSUIT_DB_COMMENTS_CATEGORY::getInstance();

        $query = $comment_db->getResultsQuery( array( 'member_id' => $member['id'] ) ) . ' ORDER BY ID DESC LIMIT 10';
        $rows  = $comment_db->get_results( $query );

        $comments = array();
        foreach ( $rows as $row ) {
            $term_ids = $relation_db->get_comment_categories( $row->ID );
            $category = 'Uncategorised';
            if ( ! empty( $term_ids ) ) {
                $cat_row  = $cat_db->get_row( $term_ids[0] );
                $category = $cat_row ? $cat_row->name : 'Uncategorised';
            }
            $comments[] = array(
                'date'     => date( 'd M Y', strtotime( $row->post_date ) ),
                'category' => $category,
                'note'     => $row->text,
            );
        }

        return array(
            'member'   => $member['name'],
            'count'    => count( $comments ),
            'comments' => $comments,
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
        return INPURSUIT_DB_USER::getInstance()->getLimitedGroups( $wp_user->ID );
    }

    private static function resolve_single_member( $name, $wp_user ) {
        $user_ids  = self::get_user_group_ids( $wp_user );
        $tax_query = array();
        if ( ! empty( $user_ids ) ) {
            $tax_query = array(
                array(
                    'taxonomy' => 'inpursuit-group',
                    'field'    => 'id',
                    'terms'    => array_map( 'intval', $user_ids ),
                ),
            );
        }

        // Temporary posts_where filter for title-only LIKE search
        $search_name  = $name;
        $title_filter = function( $where, $query ) use ( $search_name ) {
            global $wpdb;
            if ( $query->get( '_wa_title_search' ) ) {
                $where .= $wpdb->prepare(
                    " AND {$wpdb->posts}.post_title LIKE %s",
                    '%' . $wpdb->esc_like( $search_name ) . '%'
                );
            }
            return $where;
        };

        add_filter( 'posts_where', $title_filter, 10, 2 );

        $wp_query = new WP_Query( array(
            'post_type'              => INPURSUIT_MEMBERS_POST_TYPE,
            'post_status'            => 'publish',
            'posts_per_page'         => 5,
            'tax_query'              => $tax_query,
            '_wa_title_search'       => true,
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ) );

        remove_filter( 'posts_where', $title_filter, 10 );

        $posts = $wp_query->posts;

        if ( empty( $posts ) ) {
            return array( 'error' => "No member found matching \"{$name}\"." );
        }
        if ( count( $posts ) > 1 ) {
            return array(
                'error'   => 'Multiple members found. Be more specific.',
                'matches' => wp_list_pluck( $posts, 'post_title' ),
            );
        }

        return array( 'id' => $posts[0]->ID, 'name' => $posts[0]->post_title );
    }

    private static function get_term( $post_id, $taxonomy ) {
        $terms = get_the_terms( $post_id, $taxonomy );
        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return null;
        }
        return $terms[0]->name;
    }

    private static function get_last_seen( $member_id ) {
        $db    = INPURSUIT_DB::getInstance();
        $query = $db->eventsQuery( $member_id ) . ' ORDER BY post_date DESC LIMIT 1';
        $rows  = $db->get_results( $query );

        if ( empty( $rows ) ) {
            return null;
        }

        return date( 'd M Y', strtotime( $rows[0]->post_date ) ) . ' — ' . $rows[0]->text;
    }
}
