<?php
/**
 * Plugin Name: GO Last.fm
 * Plugin URI: https://janboddez.be/
 * Description: Display the (album) cover art to the tracks you most recently listened to. 
 * Version: 0.1.0
 * Author: Jan Boddez
 * Author URI: https://janboddez.be/
 */

class GO_Lastfm_Widget extends WP_Widget {
	/**
	 * Constructor for the cover art widget
	 */
	function __construct() {
		parent::__construct(
			'go_lastfm_widget', 
			__( 'Last.fm Widget', 'go_lastfm' ), 
			array( 'description' => __( 'Display the (album) cover art to the tracks you most recently listened to.', 'go_lastfm' ) )
		);
		if ( is_active_widget( false, false, $this->id_base, true ) ) {
			wp_enqueue_style( 'go-lastfm', plugins_url( 'go-lastfm/go-lastfm.css' ) );
		}
	}

	/**
	 * Renders the actual widget
	 */
	public function widget( $args, $instance ) {
		$title = apply_filters( 'widget_title', $instance['title'] );
		echo $args['before_widget'];
		if ( ! empty( $title ) ) {
			echo $args['before_title'] . $title . $args['after_title'];
		}
		/**
		 * Retrieves the album list stored previously
		 */
		$albums = get_option( 'go_lastfm_recent_albums' ); // var_dump( $albums );
		if ( ! empty( $albums ) && is_array( $albums ) ) {
			/**
			 * Loops through the list and displays the cover art and links to Last.fm
			 */
			?>
			<div class="go-recent-albums">
				<ul>
					<?php foreach( $albums as $album ) : ?>
						<li>
							<?php if ( ! empty( $album['uri'] ) ) : ?>
								<a href="<?php echo esc_url( $album['uri'] ); ?>" title="<?php echo esc_attr( wptexturize( $album['title'] ) ); ?>" target="_blank" rel="noopener"><img src="<?php echo $album['thumbnail']; ?>" alt="<?php echo esc_attr( wptexturize( $album['title'] ) ); ?>" /></a>
							<?php else : ?>
								<img src="<?php echo $album['thumbnail']; ?>" title="<?php echo esc_attr( wptexturize( $album['title'] ) ); ?>" alt="<?php echo esc_attr( wptexturize( $album['title'] ) ); ?>" />
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php
		}
		echo $args['after_widget'];
	}

	/**
	 * 'Create Widget' form; allows for a custom widget title
	 */
	public function form( $instance ) {
		if ( isset( $instance[ 'title' ] ) ) {
			$title = $instance[ 'title' ];
		} else {
			$title = __( 'Last.fm', 'go_lastfm' );
		}
		?>
		<p>
			<label for="<?php echo $this->get_field_id( 'title' ); ?>"><?php _e( 'Title:', 'go_lastfm' ); ?></label> 
			<input class="widefat" id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<?php 
	}

	public function update( $new_instance, $old_instance ) {
		$instance = array();
		$instance['title'] = ( ! empty( $new_instance['title'] ) ) ? strip_tags( $new_instance['title'] ) : '';
		return $instance;
	}
}

/**
 * 'Main' plugin class and settings
 */
class GO_Lastfm {
	/**
	 * Constructor
	 */
	public function __construct() {
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		add_action( 'widgets_init', array( $this, 'load_widget' ) );
		add_action( 'daily_load_album_list', array( $this, 'load_album_list' ) );
		add_action( 'admin_menu', array( $this, 'create_menu' ) );
	}

	/**
	 * Schedules updating the album list
	 */
	public function activate() {
		$timestamp = wp_next_scheduled( 'daily_load_album_list' );
		if( false === $timestamp ) {
			wp_schedule_event( time(), 'daily', 'daily_load_album_list' );
		}
	}

	/**
	 * Actually registers the widget defined above
	 */
	public function load_widget() {
		register_widget( 'GO_Lastfm_Widget' );
	}

	/**
	 * Updates the album list by calling the Last.fm API
	 */
	public function load_album_list() {
		$lastfm_api_key = get_option( 'go_lastfm_api_key' );
		$lastfm_user_name = get_option( 'go_lastfm_user_name' );
		$response = @file_get_contents( 'http://ws.audioscrobbler.com/2.0/?method=user.getrecenttracks&user=' . $lastfm_user_name . '&limit=200&api_key=' . $lastfm_api_key . '&format=json' );
		if ( $this->is_valid_json( $response ) ) {
			$json = json_decode( $response, true ); // Returns an associative array
			$albums = array();
			foreach( $json['recenttracks']['track'] as $track ) {
				$title = $track['artist']['#text'] . ' - ' . $track['album']['#text']; // 'Artist - Album Title'
				$thumbnail = $track['image'][2]['#text']; // Album cover URI
				$mbid = $track['album']['mbid'];
				if ( ! $this->in_album_list( $thumbnail, $albums ) ) {
					$uri = '';
					$response = @file_get_contents( 'http://ws.audioscrobbler.com/2.0/?method=album.getinfo&mbid=' . $mbid . '&api_key=' . $lastfm_api_key . '&format=json' );
					if ( $this->is_valid_json( $response ) ) {
						$json = json_decode( $response, true ); // Returns an associative array
						$uri = $json['album']['url'];
					}
					$albums[] = array(
						'title' => $title,
						'uri' => $uri,
						'thumbnail' => $thumbnail,
					);
				}
			}
			/**
			 * Stores the (updated) album list as a WordPress 'option'
			 */
			update_option( 'go_lastfm_recent_albums', $albums ); // Accepts arrays, will add option if non-existent
		}
	}

	/**
	 * Registers the plugin settings page
	 */
	public function create_menu() {
		add_options_page(
			'GO Last.fm', 
			'GO Last.fm', 
			'manage_options', 
			'go-lastfm-settings', 
			array( $this, 'settings_page' )
		);
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function register_settings() {
		register_setting( 'go-lastfm-settings-group', 'go_lastfm_api_key' );
		register_setting( 'go-lastfm-settings-group', 'go_lastfm_user_name' );
	}

	/**
	 * Renders the plugin settings form
	 */
	public function settings_page() {
	?>
	<div class="wrap">
	<h1>GO Last.fm</h1>
	<form method="post" action="options.php">
		<?php settings_fields( 'go-lastfm-settings-group' ); ?>
		<?php do_settings_sections( 'go-lastfm-settings-group' ); ?>
		<table class="form-table">
			<tr valign="top">
				<th scope="row">Last.fm API Key</th>
				<td><input class="widefat" type="text" name="go_lastfm_api_key" value="<?php echo esc_attr( get_option( 'go_lastfm_api_key' ) ); ?>" /></td>
			</tr>
			<tr valign="top">
				<th scope="row">Last.fm User Name</th>
				<td><input class="widefat" type="text" name="go_lastfm_user_name" value="<?php echo esc_attr( get_option( 'go_lastfm_user_name' ) ); ?>" /></td>
			</tr>
		</table>
		<?php submit_button(); ?>
	</form>
	</div>
	<?php
	}

	/**
	 * Helper functions
	 */
	private function is_valid_json( $str ) { 
		json_decode( $str );
		return ( json_last_error() === JSON_ERROR_NONE );
	}

	private function in_album_list( $thumbnail, $albums ) {
		foreach ( $albums as $album ) {
			if ( $album['thumbnail'] === $thumbnail ) {
				return true;
			}
		}
		return false;
	}
}

$go_lastfm = new GO_Lastfm();
