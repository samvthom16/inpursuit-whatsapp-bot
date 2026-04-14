<?php
defined( 'ABSPATH' ) || exit;

/**
 * All database queries for the WhatsApp bot.
 * Uses the parent plugin's DB classes and wpdb directly.
 */
class INPURSUIT_WA_Query_Handler {

    // -------------------------------------------------------------------------
    // Member search
    // -------------------------------------------------------------------------

    /**
     * Search for a member by name and return their profile.
     */
    public static function get_member( $name, $role = 'subscriber' ) {
        global $wpdb;

        $like    = '%' . $wpdb->esc_like( $name ) . '%';
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT ID, post_title FROM {$wpdb->posts}
             WHERE post_type = %s AND post_status = 'publish' AND post_title LIKE %s
             LIMIT 5",
            INPURSUIT_MEMBERS_POST_TYPE,
            $like
        ) );

        if ( empty( $results ) ) {
            return "No member found matching \"$name\".";
        }

        if ( count( $results ) > 1 ) {
            $lines = array( "Found " . count( $results ) . " members matching \"$name\":" );
            foreach ( $results as $row ) {
                $lines[] = "• " . $row->post_title;
            }
            $lines[] = "\nTry a more specific name.";
            return implode( "\n", $lines );
        }

        $member = $results[0];
        return self::format_member_profile( $member->ID );
    }

    /**
     * Return just the follow-up status for a member.
     */
    public static function get_member_status( $name, $role = 'subscriber' ) {
        global $wpdb;

        $like   = '%' . $wpdb->esc_like( $name ) . '%';
        $result = $wpdb->get_row( $wpdb->prepare(
            "SELECT ID, post_title FROM {$wpdb->posts}
             WHERE post_type = %s AND post_status = 'publish' AND post_title LIKE %s
             LIMIT 1",
            INPURSUIT_MEMBERS_POST_TYPE,
            $like
        ) );

        if ( ! $result ) {
            return "No member found matching \"$name\".";
        }

        $status    = self::get_member_taxonomy( $result->ID, 'inpursuit-status' );
        $group     = self::get_member_taxonomy( $result->ID, 'inpursuit-group' );
        $last_seen = self::get_last_seen( $result->ID );

        $lines = array(
            "*{$result->post_title}*",
            "Status: " . ( $status ?: 'Not set' ),
            "Group:  " . ( $group ?: 'Not set' ),
            "Last seen: " . ( $last_seen ?: 'Unknown' ),
        );

        return implode( "\n", $lines );
    }

    /**
     * List members with 'pending' / follow-up needed status.
     */
    public static function get_followup_members( $role = 'subscriber' ) {
        global $wpdb;

        // Get the term ID for the 'pending' status (or any follow-up status)
        $status_terms = get_terms( array(
            'taxonomy'   => 'inpursuit-status',
            'hide_empty' => false,
        ) );

        if ( is_wp_error( $status_terms ) || empty( $status_terms ) ) {
            return "No member statuses found in the database.";
        }

        // Look for terms suggesting follow-up needed
        $followup_keywords = array( 'pending', 'follow', 'new', 'inactive' );
        $followup_term_ids = array();

        foreach ( $status_terms as $term ) {
            foreach ( $followup_keywords as $kw ) {
                if ( stripos( $term->slug, $kw ) !== false || stripos( $term->name, $kw ) !== false ) {
                    $followup_term_ids[] = $term->term_id;
                    break;
                }
            }
        }

        if ( empty( $followup_term_ids ) ) {
            // Fall back: show all statuses so admin can decide
            $names = wp_list_pluck( $status_terms, 'name' );
            return "Available statuses: " . implode( ', ', $names ) . "\n\nNo obvious follow-up status found. Use *members <group>* to filter.";
        }

        $members = get_posts( array(
            'post_type'      => INPURSUIT_MEMBERS_POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => 20,
            'tax_query'      => array(
                array(
                    'taxonomy' => 'inpursuit-status',
                    'terms'    => $followup_term_ids,
                ),
            ),
        ) );

        if ( empty( $members ) ) {
            return "No members needing follow-up found.";
        }

        $lines = array( "*Members needing follow-up (" . count( $members ) . ")*:" );
        foreach ( $members as $m ) {
            $status = self::get_member_taxonomy( $m->ID, 'inpursuit-status' );
            $lines[] = "• {$m->post_title}" . ( $status ? " [{$status}]" : '' );
        }

        if ( count( $members ) === 20 ) {
            $lines[] = "\n_(Showing first 20)_";
        }

        return implode( "\n", $lines );
    }

    /**
     * List up to 10 members with their member ID.
     * If the WP user has group(s) assigned (via inpursuit-group user meta),
     * only members in those groups are returned.
     *
     * @param WP_User|null $wp_user
     */
    public static function get_members_list( $wp_user = null ) {
        $args = array(
            'post_type'      => INPURSUIT_MEMBERS_POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => 10,
            'orderby'        => 'title',
            'order'          => 'ASC',
        );

        // If the user has group restrictions, apply them
        if ( $wp_user ) {
            $group_term_ids = get_user_meta( $wp_user->ID, 'inpursuit-group', true );
            if ( is_array( $group_term_ids ) && ! empty( $group_term_ids ) ) {
                $args['tax_query'] = array(
                    array(
                        'taxonomy' => 'inpursuit-group',
                        'field'    => 'id',
                        'terms'    => array_map( 'intval', $group_term_ids ),
                    ),
                );
            }
        }

        $members = get_posts( $args );

        if ( empty( $members ) ) {
            return "No members found.";
        }

        // Build group label for header
        $header = "*Members (showing " . count( $members ) . ")*:";
        if ( $wp_user ) {
            $group_term_ids = get_user_meta( $wp_user->ID, 'inpursuit-group', true );
            if ( is_array( $group_term_ids ) && ! empty( $group_term_ids ) ) {
                $group_names = array();
                foreach ( $group_term_ids as $tid ) {
                    $term = get_term( (int) $tid, 'inpursuit-group' );
                    if ( $term && ! is_wp_error( $term ) ) {
                        $group_names[] = $term->name;
                    }
                }
                if ( ! empty( $group_names ) ) {
                    $header = "*Members — " . implode( ', ', $group_names ) . " (showing " . count( $members ) . ")*:";
                }
            }
        }

        $lines = array( $header );
        foreach ( $members as $m ) {
            $lines[] = "• [ID: {$m->ID}] {$m->post_title}";
        }

        return implode( "\n", $lines );
    }

    /**
     * List members in a specific group.
     */
    public static function get_members_by_group( $group_name ) {
        $term = get_term_by( 'name', $group_name, 'inpursuit-group' );
        if ( ! $term ) {
            // Try slug
            $term = get_term_by( 'slug', sanitize_title( $group_name ), 'inpursuit-group' );
        }

        if ( ! $term ) {
            // List available groups
            $terms = get_terms( array( 'taxonomy' => 'inpursuit-group', 'hide_empty' => false ) );
            if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
                $names = wp_list_pluck( $terms, 'name' );
                return "Group \"$group_name\" not found.\nAvailable groups: " . implode( ', ', $names );
            }
            return "Group \"$group_name\" not found.";
        }

        $members = get_posts( array(
            'post_type'      => INPURSUIT_MEMBERS_POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => 30,
            'tax_query'      => array(
                array(
                    'taxonomy' => 'inpursuit-group',
                    'terms'    => $term->term_id,
                ),
            ),
        ) );

        if ( empty( $members ) ) {
            return "No members found in group \"{$term->name}\".";
        }

        $lines = array( "*{$term->name}* — {$term->count} member(s):" );
        foreach ( $members as $m ) {
            $status = self::get_member_taxonomy( $m->ID, 'inpursuit-status' );
            $lines[] = "• {$m->post_title}" . ( $status ? " [{$status}]" : '' );
        }

        return implode( "\n", $lines );
    }

    // -------------------------------------------------------------------------
    // Events
    // -------------------------------------------------------------------------

    /**
     * List upcoming special dates (birthdays & weddings) for the current month
     * from the wp_ip_member_dates table.
     */
    public static function get_recent_events( $role = 'subscriber' ) {
        global $wpdb;

        $table      = $wpdb->prefix . 'ip_member_dates';
        $month      = (int) date( 'm' );
        $today_day  = (int) date( 'd' );
        $month_name = date( 'F Y' );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT md.member_id, md.event_type, md.event_date, p.post_title AS member_name
             FROM {$table} md
             INNER JOIN {$wpdb->posts} p ON p.ID = md.member_id
             WHERE p.post_status = 'publish'
               AND p.post_type = 'inpursuit-members'
               AND MONTH( md.event_date ) = %d
               AND DAY( md.event_date ) >= %d
             ORDER BY DAY( md.event_date ) ASC",
            $month,
            $today_day
        ) );

        if ( empty( $rows ) ) {
            return "No special dates remaining in {$month_name}.";
        }

        $lines = array( "*Special Dates — {$month_name}:*", "" );

        foreach ( $rows as $row ) {
            $day   = date( 'd M', strtotime( $row->event_date ) );
            $emoji = $row->event_type === 'birthday' ? '🎂' : '💍';
            $type  = ucfirst( $row->event_type );
            $lines[] = "{$emoji} *{$row->member_name}* — {$type} ({$day})";
        }

        return implode( "\n", $lines );
    }

    /**
     * Search for an event by name.
     */
    public static function get_event_by_name( $name ) {
        global $wpdb;

        $like   = '%' . $wpdb->esc_like( $name ) . '%';
        $result = $wpdb->get_row( $wpdb->prepare(
            "SELECT ID, post_title FROM {$wpdb->posts}
             WHERE post_type = %s AND post_status = 'publish' AND post_title LIKE %s
             ORDER BY post_date DESC LIMIT 1",
            INPURSUIT_EVENTS_POST_TYPE,
            $like
        ) );

        if ( ! $result ) {
            return "No event found matching \"$name\".";
        }

        return self::format_event_detail( $result->ID );
    }

    /**
     * Get attendance info for an event by name.
     */
    public static function get_event_attendance( $name, $role = 'subscriber' ) {
        global $wpdb;

        $like   = '%' . $wpdb->esc_like( $name ) . '%';
        $result = $wpdb->get_row( $wpdb->prepare(
            "SELECT ID, post_title FROM {$wpdb->posts}
             WHERE post_type = %s AND post_status = 'publish' AND post_title LIKE %s
             ORDER BY post_date DESC LIMIT 1",
            INPURSUIT_EVENTS_POST_TYPE,
            $like
        ) );

        if ( ! $result ) {
            return "No event found matching \"$name\".";
        }

        $event_id  = $result->ID;
        $event_db  = INPURSUIT_DB_EVENT::getInstance();
        $registered   = $event_db->numberOfRegisteredMembers( $event_id );
        $participated = $event_db->numberOfParticipatingMembers( $event_id );
        $percentage   = $event_db->attendantsPercentage( $event_id );

        $date = get_the_date( 'd M Y', $event_id );

        $lines = array(
            "*{$result->post_title}* ({$date})",
            "Registered:   $registered",
            "Attended:     $participated",
            "Attendance:   {$percentage}%",
        );

        return implode( "\n", $lines );
    }

    // -------------------------------------------------------------------------
    // Special dates
    // -------------------------------------------------------------------------

    /**
     * Members with birthdays this month.
     */
    public static function get_birthdays() {
        $dates_db = INPURSUIT_DB_MEMBER_DATES::getInstance();

        // Use the plugin's helper for next 30 days
        $upcoming = $dates_db->getNextOneMonthEvents( array( 'page' => 1, 'per_page' => 20 ) );

        if ( empty( $upcoming ) ) {
            return "No birthdays or anniversaries in the next 30 days.";
        }

        $birthdays    = array();
        $anniversaries = array();

        foreach ( $upcoming as $row ) {
            $member_name = get_the_title( $row->member_id );
            $date_str    = date( 'd M', strtotime( $row->event_date ) );

            if ( $row->event_type === 'birthday' ) {
                $birthdays[]    = "• {$member_name} — {$date_str}";
            } else {
                $anniversaries[] = "• {$member_name} — {$date_str}";
            }
        }

        $lines = array( "*Upcoming in the next 30 days:*" );

        if ( ! empty( $birthdays ) ) {
            $lines[] = "\n🎂 *Birthdays:*";
            foreach ( $birthdays as $b ) {
                $lines[] = $b;
            }
        }

        if ( ! empty( $anniversaries ) ) {
            $lines[] = "\n💍 *Anniversaries:*";
            foreach ( $anniversaries as $a ) {
                $lines[] = $a;
            }
        }

        return implode( "\n", $lines );
    }

    // -------------------------------------------------------------------------
    // Stats
    // -------------------------------------------------------------------------

    public static function get_stats( $role = 'subscriber' ) {
        global $wpdb;

        $total_members = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
            INPURSUIT_MEMBERS_POST_TYPE
        ) );

        $total_events = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
            INPURSUIT_EVENTS_POST_TYPE
        ) );

        // Members per status
        $statuses = get_terms( array(
            'taxonomy'   => 'inpursuit-status',
            'hide_empty' => false,
        ) );

        $lines = array(
            "*InPursuit — Quick Stats*",
            "",
            "👥 Total Members: $total_members",
            "📅 Total Events:  $total_events",
        );

        if ( ! is_wp_error( $statuses ) && ! empty( $statuses ) ) {
            $lines[] = "";
            $lines[] = "*Members by Status:*";
            foreach ( $statuses as $term ) {
                $lines[] = "• {$term->name}: {$term->count}";
            }
        }

        // Groups breakdown
        $groups = get_terms( array(
            'taxonomy'   => 'inpursuit-group',
            'hide_empty' => true,
        ) );

        if ( ! is_wp_error( $groups ) && ! empty( $groups ) ) {
            $lines[] = "";
            $lines[] = "*Members by Group:*";
            foreach ( $groups as $term ) {
                $lines[] = "• {$term->name}: {$term->count}";
            }
        }

        return implode( "\n", $lines );
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private static function format_member_profile( $member_id ) {
        $name       = get_the_title( $member_id );
        $status     = self::get_member_taxonomy( $member_id, 'inpursuit-status' );
        $group      = self::get_member_taxonomy( $member_id, 'inpursuit-group' );
        $gender     = self::get_member_taxonomy( $member_id, 'inpursuit-gender' );
        $profession = self::get_member_taxonomy( $member_id, 'inpursuit-profession' );
        $location   = self::get_member_taxonomy( $member_id, 'inpursuit-location' );
        $last_seen  = self::get_last_seen( $member_id );

        // Birthday / age
        $dates_db = INPURSUIT_DB_MEMBER_DATES::getInstance();
        $age      = $dates_db->age( $member_id );

        $lines = array(
            "*{$name}*",
            "Status:     " . ( $status ?: 'Not set' ),
            "Group:      " . ( $group ?: 'Not set' ),
            "Gender:     " . ( $gender ?: 'Not set' ),
            "Profession: " . ( $profession ?: 'Not set' ),
            "Location:   " . ( $location ?: 'Not set' ),
            "Age:        " . ( $age ? $age . ' yrs' : 'Not set' ),
            "Last seen:  " . ( $last_seen ?: 'Unknown' ),
        );

        return implode( "\n", $lines );
    }

    private static function format_event_detail( $event_id ) {
        $title    = get_the_title( $event_id );
        $date     = get_the_date( 'd M Y', $event_id );
        $type     = self::get_member_taxonomy( $event_id, 'inpursuit-event-type' );
        $group    = self::get_member_taxonomy( $event_id, 'inpursuit-group' );
        $location = self::get_member_taxonomy( $event_id, 'inpursuit-location' );

        $event_db     = INPURSUIT_DB_EVENT::getInstance();
        $registered   = $event_db->numberOfRegisteredMembers( $event_id );
        $participated = $event_db->numberOfParticipatingMembers( $event_id );
        $percentage   = $event_db->attendantsPercentage( $event_id );

        $lines = array(
            "*{$title}*",
            "Date:       $date",
            "Type:       " . ( $type ?: 'Not set' ),
            "Group:      " . ( $group ?: 'Not set' ),
            "Location:   " . ( $location ?: 'Not set' ),
            "Registered: $registered",
            "Attended:   $participated ({$percentage}%)",
        );

        return implode( "\n", $lines );
    }

    /**
     * Get the first term name for a given taxonomy on a post.
     */
    private static function get_member_taxonomy( $post_id, $taxonomy ) {
        $terms = get_the_terms( $post_id, $taxonomy );
        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return '';
        }
        return $terms[0]->name;
    }

    /**
     * Get the last event date this member attended.
     */
    private static function get_last_seen( $member_id ) {
        global $wpdb;

        $table = $wpdb->prefix . 'ip_event_member_relation';
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
