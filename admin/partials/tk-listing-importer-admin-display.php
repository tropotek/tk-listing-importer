<?php
/**
 * Provide a admin area view for the plugin
 *
 * This file is used to markup the admin-facing aspects of the plugin.
 *
 * @link       http://www.tropotek.com/
 * @since      1.0.0
 *
 * @package    Tk_Listing_Exporter
 * @subpackage Tk_Listing_Exporter/admin/partials
 */
?>

<div class="wrap">

  <div id="icon-options-general" class="icon32"></div>
  <h2><?php echo esc_html(get_admin_page_title()); ?></h2>

  <div id="poststuff">

    <div id="post-body" class="metabox-holder columns-2">

      <!-- main content -->
      <div id="post-body-content">

        <div class="meta-box-sortables ui-sortable">

          <div class="postbox">

            <h2 class="hndle"><span><?php esc_attr_e( 'Listing Import Options', $this->plugin_name); ?></span></h2>

            <div class="inside">

              <form method="post" name="import_options" action="options.php">
	              <?php
	              //Grab all options
	              $options = get_option($this->plugin_name);
	              //error_log(print_r($options, true));

	              // Cleanup
                $url = $options['url'];
                $userId = $options['userId'];
                $key = $options['key'];
                $active = $options['active'];
                $lastImport = $options['lastImport']
	              ?>

	              <?php
	              settings_fields( $this->plugin_name );
	              do_settings_sections( $this->plugin_name );
	              ?>
                <br/>


                <table class="form-table">
                  <tr>
                    <th scope="row"><label for="<?php echo $this->plugin_name;?>-url"><?php esc_attr_e('Provider URL', $this->plugin_name);?></label></th>
                    <td>
                      <input type="text" value="<?php echo $url;?>" class="regular-text" name="<?php echo $this->plugin_name;?>[url]" id="<?php echo $this->plugin_name;?>-url" placeholder="<?php esc_attr_e('Provider URL', $this->plugin_name);?>"/>
                      <p class="description"><?php esc_attr_e( 'Enter the export server URL. (EG: http://wp-example.com/)', $this->plugin_name ); ?></p>
                    </td>
                  </tr>
                  <tr>
                    <th scope="row"><label for="<?php echo $this->plugin_name;?>-key"><?php esc_attr_e('Secret Key', $this->plugin_name);?></label></th>
                    <td>
                      <input type="text" value="<?php echo $key;?>" class="regular-text" name="<?php echo $this->plugin_name;?>[key]" id="<?php echo $this->plugin_name;?>-key" placeholder="<?php esc_attr_e('Security Key', $this->plugin_name);?>"/>
                      <p class="description"><?php esc_attr_e( 'Enter the account key supplied by your listing server provider.', $this->plugin_name ); ?></p>
                    </td>
                  </tr>
                  <tr>
                    <th scope="row">
                      <label for="<?php echo $this->plugin_name;?>-userId"><?php _e( 'Import Author', $this->plugin_name ); ?></label>
                    </th>
                    <td>
                      <select required="required" name="<?php echo $this->plugin_name;?>[userId]" id="<?php echo $this->plugin_name;?>-userId">
                        <option value="0" <?php selected( $userId, '0' ); ?>><?php echo __( '-- SELECT USER --', $this->plugin_name ); ?></option>
                          <?php
                            // Create all the options of the listing authors...
                            $users = get_users();
                            foreach ($users as $user) {
                                printf('<option value="%s" %s>%s</option>'."\n", $user->data->ID, selected( $userId, $user->data->ID, false), $user->data->display_name);
                            }
                          ?>
                      </select>
                      <p class="description"><?php _e('Select the new author for all imported listings.', $this->plugin_name ); ?></p>
                    </td>
                  </tr>
                  <tr>
                    <th scope="row"><label for="<?php echo $this->plugin_name;?>-active"><?php esc_attr_e('Import Active', $this->plugin_name);?></label></th>
                    <td>
                      <input type="checkbox" value="1" class="regular-text" name="<?php echo $this->plugin_name;?>[active]" id="<?php echo $this->plugin_name;?>-active" <?php echo checked(1, $active); ?>/>
                      <p class="description"><?php esc_attr_e( 'Uncheck this to disable nightly imports.', $this->plugin_name ); ?></p>
                    </td>
                  </tr>

	                <?php
	                /*
				  ?>
													  <tr>
														<th scope="row"><label for="<?php echo $this->plugin_name;?>-import-type"><?php esc_attr_e('Import Type', $this->plugin_name);?></label></th>
														<td>
														  <select name="<?php echo $this->plugin_name;?>[import-type]" id="<?php echo $this->plugin_name;?>-import-type">
															<option value="">-- <?php esc_attr_e('Import Type', $this->plugin_name);?> --</option>
															<option value="ALL" <?php selected( $importType, 'ALL'); ?>>All Listings</option>
															<option value="AGENT" <?php selected( $importType, "AGENT"); ?>>Agent Listings Only</option>
														  </select>
														  <p class="description"><?php esc_attr_e( 'Select `Agent Only Listings` to import listings belonging to your account.', $this->plugin_name ); ?></p>
														</td>
													  </tr>
				  <?php
				  */
	                ?>
                </table>

                <p>
		              <?php submit_button(__('Save', $this->plugin_name), 'primary','submit', false); ?>
                </p>
              </form>


            </div>
            <!-- .inside -->

          </div>
          <!-- .postbox -->

        </div>
        <!-- .meta-box-sortables .ui-sortable -->

      </div>
      <!-- post-body-content -->

      <!-- sidebar -->
      <div id="postbox-container-1" class="postbox-container">

        <div class="meta-box-sortables">

          <div class="postbox">
            <!-- Toggle -->

            <h2 class="hndle"><span><?php esc_attr_e( 'Manual Import', $this->plugin_name	); ?></span></h2>

            <div class="inside">
              <p><?php esc_attr_e('Imports are automatically run nightly.', $this->plugin_name	); ?></p>
              <p><?php esc_attr_e( 'You can initiate a manual import by clicking the button below.', $this->plugin_name	); ?></p>
              <p>
                <em><small><?php esc_attr_e( 'NOTE: Large imports can take some time. If you get a browser error, the import may still be running on the server, view you listings in 10-20 min to see if the updates have taken place.', $this->plugin_name	); ?></em></small>
              </p>

              <style>
                .button.disabled {
                  cursor: progress;
                  pointer-events: none;
                  opacity: 0.5;

                }
              </style>

              <p>
                <a href="<?php echo admin_url('options-general.php?page=tk-listing-importer&import=t'); ?>" class="button button-primary disable" id="btn-import" title="Initiate a manual import" onclick="if (confirm('Warning: This will delete all existing Listings. Continue?')) ($(this).addClass('disabled'); return true; } else { return false; }"><?php esc_attr_e('Import Now', $this->plugin_name); ?></a>
                <a href="<?php echo admin_url('options-general.php?page=tk-listing-importer&import=t&dl=dl'); ?>" class="button button-primary" title="Download/View the remote import XML" target="_blank"><?php esc_attr_e('Download XML', $this->plugin_name); ?></a>
              </p>
            </div>
          </div>
          <div class="postbox">

            <h2 class="hndle"><span><?php esc_attr_e( 'Scheduled Import', $this->plugin_name	); ?></span></h2>
            <div class="inside">
                <?php
                if (Tk_Import::getInstance()->hasLock()) {
                ?>
                  <p>
                    <b>Importer Running: </b></p>
                  <p>
                    &nbsp;&nbsp;&nbsp;&nbsp;<a href="<?php echo admin_url('options-general.php?page=tk-listing-importer&clear-locks=t'); ?>" class="button button-secondary button-small" title="Clear import lock files"
                     onclick="return confirm('Warning: Only select this option if another instance of the import is not currently running.\n' +
                      'This will clear the lock file and allow another instance of the import to run.\n' +
                       'It is not advisable to run multiple instances, this can corrupt your listings and you will have to re-import the listings later once al instances have finished.');"><?php esc_attr_e('Clear Locks', $this->plugin_name); ?></a>
                  </p><?php
                } else {
//                  error_log('1. ' . get_option('timezone_string'));
//                  error_log('2. ' . date_default_timezone_get());
                    $tz = new DateTimeZone(date_default_timezone_get());
                    if (get_option('timezone_string'))
                      $tz = new DateTimeZone(get_option('timezone_string'));    // TODO: We have an issue here ???

                    if (wp_next_scheduled('tk_listing_import')) {
                        $date = new DateTime('@' . wp_next_scheduled('tk_listing_import'));
                        $date->setTimezone($tz);
                        $schedImport = $date->format('l, j M Y h:i A');
                        $schedImportTs = $date->getTimestamp();
                    }
                    $schedImport = 'None';
                    $schedImportTs = 0;

                    $pastImport = 'Never';
                    $pastImportTs = 0;
                    if ($lastImport) {
                        $date = new DateTime($lastImport);
                        $date->setTimezone($tz);
                        $pastImport = $date->format('l, j M Y h:i A');
                        $pastImportTs = $date->getTimestamp();
                    }
                ?>

                  <p>
                    <b>Last Import: </b><br/>
                    &nbsp;&nbsp;&nbsp;&nbsp;<span class="tk-last-import" data-timestamp="<?php echo $pastImportTs; ?>"><?php echo $pastImport; ?></span><br/>
                    <b>Next Import: </b><br/>
                    &nbsp;&nbsp;&nbsp;&nbsp;<span class="tk-next-import" data-timestamp="<?php echo $schedImportTs; ?>"><?php echo $schedImport; ?></span><br/>
                  </p>
                <?php
                }
                ?>
              <br/>
            </div>
            <!-- .inside -->

          </div>
          <!-- .postbox -->

        </div>
        <!-- .meta-box-sortables -->

      </div>
      <!-- #postbox-container-1 .postbox-container -->

    </div>
    <!-- #post-body .metabox-holder .columns-2 -->

    <br class="clear">
  </div>
  <!-- #poststuff -->

</div> <!-- .wrap -->
