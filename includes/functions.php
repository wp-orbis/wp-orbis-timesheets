<?php

function orbis_post_link( $id ) {
	return add_query_arg( 'p', $id, home_url( '/' ) );
}

function orbis_field_class( $class = array(), $field_id ) {
	global $orbis_errors;

	if ( isset( $orbis_errors[ $field_id ] ) ) {
		$class[] = 'error';
	}

	printf( 'class="%s"', implode( ' ', $class ) );
}

function orbis_timesheets_can_register( $timestamp ) {
	$dateline_bottom = strtotime( 'midnight -3 days +10 hours' );

	$dateline_top = strtotime( 'midnight +3 days' );

	return ( $timestamp >= $dateline_bottom ) && ( $timestamp <= $dateline_top );
}

function get_edit_orbis_work_registration_link( $id ) {
	$link = add_query_arg( array(
		'entry_id' => $id,
		'action'   => 'edit'
	), get_permalink() );
	
	return $link;
}

function orbis_timesheets_get_entry( $id ) {
	global $wpdb;
	
	$entry = false;
	
	// Query
	$query = $wpdb->prepare( "
		SELECT
			id,
			company_id,
			project_id,
			activity_id,
			description,
			date,
			number_seconds
		FROM
			$wpdb->orbis_timesheets
		WHERE
			id = %d
		;
	", $id );
	
	// Row
	$row = $wpdb->get_row( $query );
	
	if ( $row) {
		$entry = new Orbis_Timesheets_TimesheetEntry();
	
		$entry->id          = $row->id;
		$entry->company_id  = $row->company_id;
		$entry->project_id  = $row->project_id;
		$entry->activity_id = $row->activity_id;
		$entry->description = $row->description;
		$entry->set_date( new DateTime( $row->date ) );
		$entry->time        = $row->number_seconds;
	}
	
	return $entry;
}

function orbis_insert_timesheet_entry( $entry ) {
	global $wpdb;
	
	$result = false;

	// Auto complete company ID
	if ( empty( $entry->company_id ) && ! empty( $entry->project_id ) ) {
		$query = $wpdb->prepare( "SELECT principal_id FROM $wpdb->orbis_projects WHERE id = %d;", $entry->project_id );

		$entry->company_id = $wpdb->get_var( $query );
	}
		
	if ( empty( $entry->company_id ) && ! empty( $entry->subscription_id ) ) {
		$query = $wpdb->prepare( "SELECT company_id FROM $wpdb->orbis_subscriptions WHERE id = %d;", $entry->subscription_id );
		
		$entry->company_id = $wpdb->get_var( $query );
	}

	// Data
	$data   = array();
	$format = array();
		
	$data['created']   = date( 'Y-m-d H:i:s' );
	$format['created'] = '%s';
		
	$data['user_id']   = $entry->person_id;
	$format['user_id'] = '%d';
		
	$data['company_id']   = $entry->company_id;
	$format['company_id'] = '%d';
		
	if ( ! empty( $entry->project_id ) ) {
		$data['project_id']   = $entry->project_id;
		$format['project_id'] = '%d';
	}
		
	$data['activity_id']   = $entry->activity_id;
	$format['activity_id'] = '%d';
		
	$data['description']   = $entry->description;
	$format['description'] = '%s';
		
	$data['date']   = $entry->get_date()->format( 'Y-m-d' );
	$format['date'] = '%s';
		
	$data['number_seconds']   = $entry->time;
	$format['number_seconds'] = '%d';

	// Insert or update
	if ( empty( $entry->id ) ) {
		$result = $wpdb->insert(
			$wpdb->orbis_timesheets,
			$data,
			$format
		);

		if ( $result ) {
			$entry->id = $wpdb->insert_id;
		}
	} else {
		$result = $wpdb->update(
			$wpdb->orbis_timesheets,
			$data,
			array( 'id' => $entry->id ),
			$foramt,
			array( 'id' => '%d' )
		);
	}

	return $result;
}

function orbis_timesheets_get_entry_from_input( $type = INPUT_POST ) {
	$entry = new Orbis_Timesheets_TimesheetEntry();
	
	$entry->id              = filter_input( $type, 'orbis_registration_id', FILTER_SANITIZE_STRING );
	$entry->company_id      = filter_input( $type, 'orbis_registration_company_id', FILTER_SANITIZE_STRING );
	$entry->project_id      = filter_input( $type, 'orbis_registration_project_id', FILTER_SANITIZE_STRING );
	$entry->subscription_id = filter_input( $type, 'orbis_registration_subscription_id', FILTER_SANITIZE_STRING );
	$entry->activity_id     = filter_input( $type, 'orbis_registration_activity_id', FILTER_SANITIZE_STRING );
	$entry->description     = filter_input( $type, 'orbis_registration_description', FILTER_SANITIZE_STRING );
	
	$date_string     = filter_input( $type, 'orbis_registration_date', FILTER_SANITIZE_STRING );
	if ( ! empty( $date_string ) ) {
		$entry->set_date( new DateTime( $date_string) );
	}

	if ( filter_has_var( $type, 'orbis_registration_time' ) ) {
		$entry->time = orbis_filter_time_input( $type, 'orbis_registration_time' );
	}
	
	if ( filter_has_var( $type, 'orbis_registration_hours' ) ) {
		$time = 0;
		
		$hours   = filter_input( $type, 'orbis_registration_hours', FILTER_VALIDATE_INT );
		$minutes = filter_input( $type, 'orbis_registration_minutes', FILTER_VALIDATE_INT );
		
		$time += $hours * 3600;
		$time += $minutes * 60;
		
		$entry->time = $time;
	}
	
	$entry->user_id         = get_current_user_id();
	$entry->person_id       = get_user_meta( $entry->user_id, 'orbis_legacy_person_id', true );
	
	return $entry;
}

function orbis_timesheets_maybe_add_entry() {
	global $orbis_errors;

	// Add
	if ( filter_has_var( INPUT_POST, 'orbis_timesheets_add_registration' ) ) {
		$entry = orbis_timesheets_get_entry_from_input();

		// Verify nonce
		$nonce = filter_input( INPUT_POST, 'orbis_timesheets_new_registration_nonce', FILTER_SANITIZE_STRING );
		if ( wp_verify_nonce( $nonce, 'orbis_timesheets_add_new_registration' ) ) {
			if ( empty( $entry->company_id ) && empty( $entry->project_id ) && empty( $entry->subscription_id ) ) {
				orbis_timesheets_register_error( 'orbis_registration_company_id', '' ); // __( 'You have to specify an company.', 'orbis_timesheets' ) );
				orbis_timesheets_register_error( 'orbis_registration_project_id', '' ); // __( 'You have to specify an project.', 'orbis_timesheets' ) );
				orbis_timesheets_register_error( 'orbis_registration_subscription_id', '' ); // __( 'You have to specify an subscription.', 'orbis_timesheets' ) );
				
				orbis_timesheets_register_error( 'orbis_registration_on', __( 'You have to specify an company or project.', 'orbis_timesheets' ) );
			}
	
			$required_word_count = 2;
			if ( str_word_count( $entry->description ) < $required_word_count ) {
				orbis_timesheets_register_error( 'orbis_registration_description', sprintf( __( 'You have to specify an description (%d words).', 'orbis_timesheets' ), $required_word_count ) );
			}
	
			if ( empty( $entry->time ) ) {
				// $orbis_errors['orbis_registration_time'] = __( 'You have to specify an time.', 'orbis_timesheets' );
			}
	
			if ( empty( $entry->person_id ) ) {
				orbis_timesheets_register_error( 'orbis_registration_person_id', sprintf(
						__( 'Who are you? <a href="%s">Edit your user profile</a> and enter you Orbis legacy person ID.', 'orbis_timesheets' ),
						esc_attr( get_edit_user_link( $user_id ) )
				) );
			}
	
			if ( empty( $entry->activity_id ) ) {
				orbis_timesheets_register_error( 'orbis_registration_activity_id', __( 'You have to specify an activity.', 'orbis_timesheets' ) );
			}
	
			if ( ! orbis_timesheets_can_register( $entry->get_date()->format( 'U' ) ) ) {
				orbis_timesheets_register_error( 'orbis_registration_date', __( 'You can not register on this date.', 'orbis_timesheets' ) );
			}
			
			$message = empty( $entry->id ) ? 'added' : 'updated';
	
			if ( empty( $orbis_errors ) ) {
				$result = orbis_insert_timesheet_entry( $entry );

				if ( $result ) {
					$url = add_query_arg( array(
						'entry_id' => false,
						'action'   => false,
						'message'  => $message,
						'date'     => $entry->get_date()->format( 'Y-m-d' ),
					) );

					wp_redirect( $url );
					
					exit;
				} else {
					orbis_timesheets_register_error( 'orbis_registration_error', __( 'Could not add timesheet entry.', 'orbis_timesheets' ) );
				}
			}
		}
	}
}

add_action( 'template_redirect', 'orbis_timesheets_maybe_add_entry' );

function orbis_timesheets_init() {
	// Errors
	global $orbis_errors;
	
	$orbis_errors = array();
}

add_action( 'init', 'orbis_timesheets_init', 1 );

function orbis_timesheets_register_error( $name, $error ) {
	// Errors
	global $orbis_errors;
	
	$orbis_errors[$name] = $error;

	return $orbis_errors;
}
