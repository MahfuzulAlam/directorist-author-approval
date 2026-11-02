<?php

defined( 'ABSPATH' ) || exit;

final class DAA_Settings {
	public static function boot() {
		add_filter( 'atbdp_extension_settings_submenu', array( __CLASS__, 'submenu' ) );
		add_filter( 'atbdp_listing_type_settings_field_list', array( __CLASS__, 'fields' ) );
	}

	public static function submenu( $submenu ) {
		$submenu['daa_author_approval'] = array(
			'label'    => __( 'Author Approval', 'directorist-author-approval' ),
			'icon'     => '<i class="fa fa-user-check" aria-hidden="true"></i>',
			'sections' => array(
				'daa_behavior' => array(
					'title'       => __( 'Registration and approval', 'directorist-author-approval' ),
					'description' => __( 'Review applications at Users → Author Approvals. Existing accounts keep their current access.', 'directorist-author-approval' ),
					'fields'      => array( 'daa_enabled', 'daa_allow_reapply', 'daa_registration_label', 'daa_registration_help', 'daa_confirmation_message', 'daa_verification_message' ),
				),
				'daa_notifications' => array(
					'title'       => __( 'Notification delivery', 'directorist-author-approval' ),
					'description' => __( 'Messages use the site’s WordPress email configuration.', 'directorist-author-approval' ),
					'fields'      => array( 'daa_admin_notifications', 'daa_user_notifications' ),
				),
				'daa_admin_email' => array(
					'title'       => __( 'Administrator request email', 'directorist-author-approval' ),
					'description' => self::tokens_description(),
					'fields'      => array( 'daa_admin_subject', 'daa_admin_message' ),
				),
				'daa_pending_email' => array(
					'title'       => __( 'Pending applicant email', 'directorist-author-approval' ),
					'description' => self::tokens_description(),
					'fields'      => array( 'daa_pending_subject', 'daa_pending_message' ),
				),
				'daa_approved_email' => array(
					'title'       => __( 'Approved applicant email', 'directorist-author-approval' ),
					'fields'      => array( 'daa_approved_subject', 'daa_approved_message' ),
				),
				'daa_rejected_email' => array(
					'title'       => __( 'Rejected applicant email', 'directorist-author-approval' ),
					'fields'      => array( 'daa_rejected_subject', 'daa_rejected_message' ),
				),
				'daa_dashboard' => array(
					'title'       => __( 'Dashboard status messages', 'directorist-author-approval' ),
					'fields'      => array( 'daa_dashboard_pending', 'daa_dashboard_approved', 'daa_dashboard_rejected', 'daa_reapply_label' ),
				),
			),
		);
		return $submenu;
	}

	private static function tokens_description() {
		return __( 'Placeholders: {username}, {email}, {registered}, {site_name}, {dashboard_url}, {review_url}.', 'directorist-author-approval' );
	}

	public static function fields( $fields ) {
		$defaults = DAA_Plugin::defaults();
		$labels = array(
			'enabled'              => __( 'Enable Author registration approval', 'directorist-author-approval' ),
			'allow_reapply'        => __( 'Allow rejected applicants to request again', 'directorist-author-approval' ),
			'admin_notifications' => __( 'Email administrator about new requests', 'directorist-author-approval' ),
			'user_notifications'  => __( 'Email applicants about requests and decisions', 'directorist-author-approval' ),
			'registration_label'   => __( 'Registration checkbox label', 'directorist-author-approval' ),
			'registration_help'    => __( 'Registration choice help text', 'directorist-author-approval' ),
			'confirmation_message' => __( 'Registration confirmation message', 'directorist-author-approval' ),
			'verification_message' => __( 'Email verification message', 'directorist-author-approval' ),
			'admin_subject'       => __( 'Administrator email subject', 'directorist-author-approval' ),
			'admin_message'       => __( 'Administrator email message', 'directorist-author-approval' ),
			'pending_subject'     => __( 'Pending email subject', 'directorist-author-approval' ),
			'pending_message'     => __( 'Pending email message', 'directorist-author-approval' ),
			'approved_subject'    => __( 'Approved email subject', 'directorist-author-approval' ),
			'approved_message'    => __( 'Approved email message', 'directorist-author-approval' ),
			'rejected_subject'    => __( 'Rejected email subject', 'directorist-author-approval' ),
			'rejected_message'    => __( 'Rejected email message', 'directorist-author-approval' ),
			'dashboard_pending'   => __( 'Pending dashboard message', 'directorist-author-approval' ),
			'dashboard_approved'  => __( 'Approved dashboard message', 'directorist-author-approval' ),
			'dashboard_rejected'  => __( 'Rejected dashboard message', 'directorist-author-approval' ),
			'reapply_label'       => __( 'Request again button label', 'directorist-author-approval' ),
		);
		$toggles = array( 'enabled', 'allow_reapply', 'admin_notifications', 'user_notifications' );
		$textareas = array( 'admin_message', 'pending_message', 'approved_message', 'rejected_message', 'dashboard_pending', 'dashboard_approved', 'dashboard_rejected', 'registration_help', 'verification_message', 'confirmation_message' );
		foreach ( $defaults as $key => $value ) {
			$fields[ 'daa_' . $key ] = array(
				'type'  => in_array( $key, $toggles, true ) ? 'toggle' : ( in_array( $key, $textareas, true ) ? 'textarea' : 'text' ),
				'label' => $labels[ $key ],
				'value' => in_array( $key, $toggles, true ) ? (bool) $value : $value,
			);
		}
		return $fields;
	}
}
