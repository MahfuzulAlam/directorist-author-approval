<?php

defined( 'ABSPATH' ) || exit;

final class DAA_Admin {
	const SLUG = 'directorist-author-approval';

	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_daa_decide', array( __CLASS__, 'handle_decision' ) );
		add_action( 'admin_post_daa_reapply', array( __CLASS__, 'handle_reapply' ) );
		add_filter( 'manage_users_columns', array( __CLASS__, 'users_column' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'users_column_value' ), 10, 3 );
	}

	public static function review_url() {
		return admin_url( 'users.php?page=' . self::SLUG );
	}

	public static function menu() {
		add_users_page(
			__( 'Author Approvals', 'directorist-author-approval' ),
			__( 'Author Approvals', 'directorist-author-approval' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot manage Author approvals.', 'directorist-author-approval' ) );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'pending';
		if ( ! in_array( $tab, array( 'pending', 'approved', 'rejected' ), true ) ) {
			$tab = 'pending';
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Directorist Author Approvals', 'directorist-author-approval' ); ?></h1>
			<?php self::render_feedback(); ?>
			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Author approval sections', 'directorist-author-approval' ); ?>">
				<?php
				$tabs = array(
					'pending'  => __( 'Pending', 'directorist-author-approval' ),
					'approved' => __( 'Approved', 'directorist-author-approval' ),
					'rejected' => __( 'Rejected', 'directorist-author-approval' ),
				);
				foreach ( $tabs as $key => $label ) {
					$url = add_query_arg( 'tab', $key, self::review_url() );
					echo '<a class="nav-tab ' . esc_attr( $tab === $key ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
				}
				?>
			</nav>
			<?php
			self::render_requests( $tab );
			?>
		</div>
		<?php
	}

	private static function render_feedback() {
		$notice = isset( $_GET['daa_notice'] ) ? sanitize_key( wp_unslash( $_GET['daa_notice'] ) ) : '';
		$messages = array(
			'approved' => __( 'Author request approved.', 'directorist-author-approval' ),
			'rejected' => __( 'Author request rejected.', 'directorist-author-approval' ),
			'failed'   => __( 'The request could not be changed. Refresh the page and try again.', 'directorist-author-approval' ),
		);
		if ( isset( $messages[ $notice ] ) ) {
			echo '<div class="notice ' . esc_attr( 'failed' === $notice ? 'notice-error' : 'notice-success' ) . ' is-dismissible"><p>' . esc_html( $messages[ $notice ] ) . '</p></div>';
		}
	}

	private static function render_requests( $status ) {
		$page  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$limit = 20;
		$query = new WP_User_Query(
			array(
				'meta_key'    => DAA_Plugin::STATUS_META,
				'meta_value'  => $status,
				'number'      => $limit,
				'offset'      => ( $page - 1 ) * $limit,
				'orderby'     => 'registered',
				'order'       => 'DESC',
				'count_total' => true,
			)
		);
		$users = $query->get_results();
		?>
		<p><?php echo esc_html( sprintf( __( '%d %s requests', 'directorist-author-approval' ), $query->get_total(), $status ) ); ?></p>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Username', 'directorist-author-approval' ); ?></th>
				<th><?php esc_html_e( 'Email', 'directorist-author-approval' ); ?></th>
				<th><?php esc_html_e( 'Registered', 'directorist-author-approval' ); ?></th>
				<th><?php esc_html_e( 'Requested', 'directorist-author-approval' ); ?></th>
				<th><?php esc_html_e( 'Status', 'directorist-author-approval' ); ?></th>
				<?php if ( 'pending' === $status ) : ?><th><?php esc_html_e( 'Actions', 'directorist-author-approval' ); ?></th><?php endif; ?>
			</tr></thead>
			<tbody>
			<?php if ( ! $users ) : ?>
				<tr><td colspan="<?php echo esc_attr( 'pending' === $status ? 6 : 5 ); ?>"><?php esc_html_e( 'No requests in this section.', 'directorist-author-approval' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $users as $user ) : ?>
					<tr id="daa-user-<?php echo esc_attr( $user->ID ); ?>">
						<td><a href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>"><?php echo esc_html( $user->user_login ); ?></a></td>
						<td><a href="mailto:<?php echo esc_attr( $user->user_email ); ?>"><?php echo esc_html( $user->user_email ); ?></a></td>
						<td><?php echo esc_html( self::date_label( $user->user_registered ) ); ?></td>
						<td><?php echo esc_html( self::date_label( get_user_meta( $user->ID, DAA_Plugin::REQUESTED_META, true ) ) ); ?></td>
						<td><strong><?php echo esc_html( ucfirst( $status ) ); ?></strong></td>
						<?php if ( 'pending' === $status ) : ?>
							<td>
								<?php self::decision_form( $user->ID, 'approved' ); ?>
								<?php self::decision_form( $user->ID, 'rejected' ); ?>
							</td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		<?php
		$total_pages = (int) ceil( $query->get_total() / $limit );
		if ( $total_pages > 1 ) {
			$base = add_query_arg( array( 'tab' => $status, 'paged' => '%#%' ), self::review_url() );
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => $base, 'format' => '', 'current' => $page, 'total' => $total_pages ) ) ) . '</div></div>';
		}
	}

	private static function date_label( $value ) {
		if ( ! $value ) {
			return '—';
		}
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $value . ' UTC' ) );
	}

	private static function decision_form( $user_id, $decision ) {
		$label = 'approved' === $decision ? __( 'Approve', 'directorist-author-approval' ) : __( 'Reject', 'directorist-author-approval' );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px">
			<input type="hidden" name="action" value="daa_decide" />
			<input type="hidden" name="user_id" value="<?php echo esc_attr( $user_id ); ?>" />
			<input type="hidden" name="decision" value="<?php echo esc_attr( $decision ); ?>" />
			<?php wp_nonce_field( 'daa_decide_' . $user_id ); ?>
			<button type="submit" class="button <?php echo esc_attr( 'approved' === $decision ? 'button-primary' : '' ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	public static function handle_decision() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'directorist-author-approval' ), '', array( 'response' => 403 ) );
		}
		$user_id  = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		check_admin_referer( 'daa_decide_' . $user_id );
		$result = DAA_Plugin::decide( $user_id, $decision );
		$notice = is_wp_error( $result ) ? 'failed' : $decision;
		wp_safe_redirect( add_query_arg( array( 'tab' => 'pending', 'daa_notice' => $notice ), self::review_url() ) );
		exit;
	}

	public static function handle_reapply() {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Please sign in first.', 'directorist-author-approval' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'daa_reapply' );
		$result = DAA_Plugin::request_author( get_current_user_id() );
		$url    = class_exists( 'ATBDP_Permalink' ) ? ATBDP_Permalink::get_dashboard_page_link() : home_url( '/' );
		wp_safe_redirect( add_query_arg( 'daa_reapply', is_wp_error( $result ) ? 'failed' : 'pending', $url ) );
		exit;
	}

	public static function users_column( $columns ) {
		$columns['daa_author_status'] = __( 'Author Approval', 'directorist-author-approval' );
		return $columns;
	}

	public static function users_column_value( $value, $column, $user_id ) {
		if ( 'daa_author_status' !== $column ) {
			return $value;
		}
		$status = DAA_Plugin::status( $user_id );
		return $status ? esc_html( ucfirst( $status ) ) : '—';
	}
}
