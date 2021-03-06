<div class="crawl_settings" style="display:none;">

<section class="wp2static-content wp2static-flex">
  <div class="content" style="max-width:30%">
    <h2><?php echo __( 'Crawl Caching', 'static-html-output-plugin' ); ?></h2>
  </div>

  <div class="content">
    <p>Don't recrawl files crawled within last n period.</p>

    <select name="crawl_caching_time_unit" id="crawl_caching">

    <?php
        // TODO: shift this into helper function for select
        $increments = array( 1, 5, 10, 25, 50, 100, 500, 1000, 999999 );

    foreach ( $increments as $increment ) :
        if ( $increment == 999999 ) : ?>
            <option value="999999"<?php echo $this->options->crawl_caching_time_unit == $increment ? ' selected' : ''; ?>>Maximum</option>
            <?php else : ?>
            <option value="<?php echo $increment; ?>"<?php echo $this->options->crawl_caching_time_unit == $increment ? ' selected' : ''; ?>><?php echo $increment; ?></option>
      
        <?php endif;
            endforeach; ?>

    </select>

    <select name="crawl_caching_time_period" id="crawl_caching_time_period">

        <?php
        // TODO: shift this into helper function for select
        $increments = [
            'Minutes',
            'Hours',
            'Days',
        ];

        foreach ( $increments as $increment ) :
            if ( $increment == 999999 ) : ?>
            <option value="999999"<?php echo $this->options->crawl_caching_time_period == $increment ? ' selected' : ''; ?>>Maximum</option>
        <?php else : ?>
            <option value="<?php echo $increment; ?>"<?php echo $this->options->crawl_caching_time_period == $increment ? ' selected' : ''; ?>><?php echo $increment; ?></option>
      
        <?php endif;
            endforeach; ?>
    
      </select>

        <?php $tpl->displayCheckbox( $this, 'dontUseCrawlCaching', 'Disregard cache and crawl everything' ); ?>

      <button id="deleteCrawlCache" class="wp2static-btn btn-sm mg-top10">Delete Crawl Cache</button>
  </div>
</section>

<section class="wp2static-content wp2static-flex">
  <div class="content" style="max-width:30%">
    <h2><?php echo __( 'Crawl Increment', 'static-html-output-plugin' ); ?></h2>
  </div>

  <div class="content">
  <select name="crawl_increment" id="crawl_increment">
    <?php
    // TODO: shift this into helper function for select
    $increments = array( 1, 5, 10, 25, 50, 100, 500, 1000, 999999 );

    foreach ( $increments as $increment ) :
        if ( $increment == 999999 ) : ?>
            <option value="999999"<?php echo $this->options->crawl_increment == $increment ? ' selected' : ''; ?>>Maximum</option>
<?php else : ?>
            <option value="<?php echo $increment; ?>"<?php echo $this->options->crawl_increment == $increment ? ' selected' : ''; ?>><?php echo $increment; ?></option>
  
    <?php endif;
         endforeach; ?>
          </select><br>
  
          <p>This is set to 1, by default, in order to allow exporting on low-resource environments, such as shared hosting servers. Each increment is the amount of files the server will try to process on each request that the browser sends it. Incrementing this will speed up your exports, by processing more are a time. If your export is failing, due to resource (memory, CPU) limits being reached, try setting this to a lower number.</p>
  </div>
</section>

<section class="wp2static-content wp2static-flex">
  <div class="content" style="max-width:30%">
    <h2><?php echo __( 'Include Discovered Assets', 'static-html-output-plugin' ); ?></h2>
  </div>
  <div class="content">
    <?php $tpl->displayCheckbox( $this, 'includeDiscoveredAssets', 'Include Discovered Assets' ); ?>

    <p>As we crawl the site, force-include any static assets found within the page (images, fonts, css, etc). Must have a supported file extension to be included.</p>
  </div>
</section>

<section class="wp2static-content wp2static-flex">
  <div class="content" style="max-width:30%">
    <h2><?php echo __( 'Use basic authentication', 'static-html-output-plugin' ); ?></h2>
  </div>

  <div class="content">
    <?php $tpl->displayCheckbox( $this, 'useBasicAuth', 'My WP site requires Basic Auth to access' ); ?>
  </div>
</section>

<section class="wp2static-content wp2static-flex no-tb-bs pd-top0">
  <div class="content" style="max-width:30%">
    <h2><?php echo __( 'Basic auth user', 'static-html-output-plugin' ); ?></h2>
  </div>
  <div class="content">
    <?php $tpl->displayTextfield( $this, 'basicAuthUser', 'Basic Auth user', '', '' ); ?>
  </div>
</section>

<section class="wp2static-content wp2static-flex no-tb-bs pd-top0">
  <div class="content" style="max-width:30%">
    <h2><?php echo __( 'Basic auth password', 'static-html-output-plugin' ); ?></h2>
  </div>
  <div class="content">
    <?php $tpl->displayTextfield( $this, 'basicAuthPassword', 'Basic Auth password', '', 'password' ); ?>
  </div>
</section>

<section class="wp2static-content wp2static-flex no-tb-bs pd-top0">
  <div class="content" style="max-width:30%">
    <h2><?php echo __( 'Custom crawling port', 'static-html-output-plugin' ); ?></h2>
  </div>
  <div class="content">
    <?php $tpl->displayTextfield( $this, 'basicAuthPassword', 'Basic Auth password', '', 'password' ); ?>
  </div>
</section>

<section class="wp2static-content wp2static-flex no-tb-bs pd-top0">
  <div class="content" style="max-width:30%">
    <h2><?php echo __( 'Custom crawling user-agent', 'static-html-output-plugin' ); ?></h2>
  </div>
  <div class="content">
    <?php $tpl->displayTextfield( $this, 'crawlUserAgent', 'Custom crawling user-agent', '' ); ?>
    <p><em>Override the request HTTP header User-Agent (defaults to WP2Static.com).</em></p>
  </div>
</section>

<section class="wp2static-content wp2static-flex">
  <div class="content" style="max-width:30%">
    <h2><?php echo __( 'Exclude certain URLs', 'static-html-output-plugin' ); ?></h2>
  </div>

  <div class="content">
    <p>Where the plugin has detected too many files and you want to exclude certain URLs from being crawled, please specify these URLs here.</p>

    <p><em>You can enter this as a partial string or full path</em></p>

    <pre>
      <code><?php echo $this->site_info['site__url']; ?>/wp-content/themes/twentyseventeen/banana.jpg</code>
      <code>/my_pricelist.pdf</code>
      <code>.js</code>
    </pre>

    <textarea class="wp2static-textarea" name="excludeURLs" id="excludeURLs" rows="5" cols="10"><?php echo $this->options->excludeURLs ? $this->options->excludeURLs : ''; ?></textarea>
  </div>
</section>

<section class="wp2static-content wp2static-flex">
  <div class="content" style="max-width:30%">
    <h2><?php echo __( 'Parse CSS files', 'static-html-output-plugin' ); ?></h2>
  </div>

  <div class="content">
    <?php $tpl->displayCheckbox( $this, 'parse_css', 'Parse CSS files' ); ?>

    <p>This will result in better exports, but will consume more memory on the server. Try disabling this if you're unable to complete your export and suspect it's running out of memory.</p>
  </div>
</section>

<section class="wp2static-content wp2static-flex">
  <div class="content" style="max-width:30%">
    <h2><?php echo __( 'Crawl Delay', 'static-html-output-plugin' ); ?></h2>
  </div>

  <div class="content">
    <select name="crawl_delay" id="crawl_delay">

    <?php
      // TODO: shift this into helper function for select
      $delays = array( 0, 0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9, 1, 2, 3, 4, 10 );

    foreach ( $delays as $delay ) : ?>
              <option value="<?php echo $delay; ?>"<?php echo $this->options->crawl_delay == $delay ? ' selected' : ''; ?>><?php echo $delay; ?></option>
        <?php endforeach; ?>
    
    </select>

    <p>This is set to 0, by default, for better performance, but if exports are failing and no error appears with Debug Log enabled or you see "Too many files open" or such errors in the server logs, try increasing this value until you get a successful export.</p>
  </div>
</section>

</div> <!-- end crawling settings -->
