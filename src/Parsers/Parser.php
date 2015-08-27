<?php namespace WordPressPluginFeed\Parsers;

use Exception;

use Carbon\Carbon;
use Symfony\Component\DomCrawler\Crawler;
use Zend\Cache\StorageFactory;
use Zend\Http\Client;

use WordPressPluginFeed\Release;
use WordPressPluginFeed\Tag;

/**
 * Main class that parses WordPress.org plugin profiles
 * 
 * @author  David Martínez <contacto@davidmartinez.net>
 */
class Parser
{
    /**
     * List of plugins with specific update log (plugin => class)
     *
     * @var array
     */
    protected static $aliases = array
    (
        'buddypress'                 => 'OpenSource\\BuddyPressParser',
        'google-sitemap-generator'   => 'OpenSource\\GoogleXMLSitemapsParser',

        'all-in-one-seo-pack'        => 'Proprietary\\AllInOneSEOPackParser',
        'gravityforms'               => 'Proprietary\\GravityFormsParser',
        'revslider'                  => 'Proprietary\\RevolutionSliderParser',
        'js-composer'                => 'Proprietary\\VisualComposerParser',
        'sitepress-multilingual-cms' => 'Proprietary\\WPMLParser',
        'ubermenu'                   => 'Proprietary\\UberMenuParser',
        'ultimate-vc-addons'         => 'Proprietary\\UltimateVCAddonsParser'
    );

    /**
     * Plugin name
     *
     * @var string
     */
    public $plugin = null;
    
    /**
     * Stability filter
     *
     * @var string
     */
    public $stability = 'stable';
    
    /**
     * Plugin title
     *
     * @var string
     */
    public $title = null;
    
    /**
     * Plugin short description
     *
     * @var string
     */
    public $description = null;
    
    /**
     * Plugin image
     * 
     * @var array
     */
    public $image = array
    (
        'uri' => "http://ps.w.org/%s/assets/icon-128x128.png",
        'height' => 128,
        'width' => 128        
    );

    /**
     * Plugin URL at WordPress.org
     *
     * @var string
     */
    public $link = null;
    
    /**
     * Feed URL
     *
     * @var string
     */
    public $feed_link = null;
    
    /**
     * Last release date
     *
     * @var \Carbon\Carbon
     */
    public $modified = null;
    
    /**
     * Release list
     *
     * @var array
     */
    protected $releases = array();
    
    /**
     * Subversion tag list 
     * 
     * @var array
     */
    protected $tags = array();
    
    /**
     * Source URLs 
     *
     * @var array
     */
    protected $sources = array
    (
        'profile'   => 'https://wordpress.org/plugins/%s/changelog/',
        'tags'      => 'https://plugins.trac.wordpress.org/browser/%s/tags?order=date&desc=1'
    );

    /**
     * CLI call
     *
     * @var bool
     */
    protected $cli = false;

    /**
     * HTTP client instance
     *
     * @var \Zend\Http\Client
     */
    protected $http = null;
    
    /**
     * Cache handler instance
     *
     * @var \Zend\Cache\StorageFactory
     */
    protected $cache = null;
    
    /**
     * Load plugin data
     * 
     * @param   string  $plugin
     * @param   string  $stability
     */
    public function __construct($plugin, $stability = null)
    {
        $this->plugin = $plugin;

        // load .env
        $env_path = dirname(dirname(dirname(__FILE__)));

        if(file_exists("$env_path/.env"))
        {
            $dotenv = new \Dotenv\Dotenv($env_path);
            $dotenv->load();
        }

        // error handler only for web calls
        if(php_sapi_name() != 'cli')
        {
            set_error_handler(array($this, 'error'));
        }
        else
        {
            $this->cli = true;
        }

        // feed link
        if($this->cli == false)
        {
            $host = filter_input(INPUT_SERVER, 'HTTP_HOST');
            $request = filter_input(INPUT_SERVER, 'REQUEST_URI');
            $this->feed_link = "http://{$host}{$request}";
        }
        // Atom feeds require a link or "self" keyword
        else
        {
            $this->feed_link = "self";
        }

        // default stability if not set
        if(empty($stability))
        {
            $stability = getenv('RELEASE_STABILITY');
        }
        
        // stability filter
        if($stability == 'any')
        {
            $this->stability = false;
        }
        else
        {
            $this->stability = '/(' . str_replace(',', '|', $stability) . ')/';
        }
        
        // external link if not defined
        if(empty($this->link))
        {
            $this->link = "https://wordpress.org/plugins/$plugin/";
        }

        // Zend HTTP Client instance
        $this->http = new Client();
        $this->http->setOptions(array
        (
            'timeout' => 30
        ));
        
        // use cURL if exists
        if(function_exists('curl_init'))
        {
            $this->http->setOptions(array
            (
                'adapter' => 'Zend\Http\Client\Adapter\Curl'
            ));
        }
        
        // cache instance
        $this->cache = StorageFactory::factory(array
        (
            'adapter' => array
            (
                'name' => 'filesystem', 
                'options' => array
                (
                    'cache_dir' => dirname(dirname(__DIR__)) . '/cache',
                    'ttl' => 3600
                )
            ),
            'plugins' => array
            (
                'exception_handler' => array('throw_exceptions' => false)
            )
        ));
        
        // load releases after class config
        try
        {
            $this->loadReleases();
        }
        catch(Exception $exception)
        {
            $this->exception($exception);
        }
    }
    
    /**
     * Clear expired cache after work is done
     */
    public function __destruct() 
    {
        if($this->cache instanceof StorageFactory)
        {
            $this->cache->clearExpired();
        }
    }

    /**
     * Get a parser class instance based on plugin name
     *
     * @param   string  $plugin
     * @param   string  $stability
     * @return  \WordPressPluginFeed\Parsers\Parser
     */
    public static function getInstance($plugin, $stability = null)
    {
        if(isset(self::$aliases[$plugin]))
        {
            $class = 'WordPressPluginFeed\\Parsers\\' . self::$aliases[$plugin];
        }
        else
        {
            $class = 'WordPressPluginFeed\\Parsers\\Parser';
        }

        return new $class($plugin, $stability);
    }
    
    /**
     * Get HTML code from changelog tab (results are cached)
     * 
     * @link    http://framework.zend.com/manual/2.4/en/modules/zend.http.client.html
     * @param   string  $type   profile, tags or image
     * @param   strign  $append query string or other parameters
     * @return  string
     */
    public function fetch($type = 'profile', $append = null)
    {
        $code = false;
        
        if(isset($this->sources[$type]) && $this->sources[$type])
        {
            $uri = $this->sources[$type] . $append;
            $source = sprintf($uri, $this->plugin);
            $key = sha1($source);

            $code = $this->cache->getItem($key, $success);
            if($success == false)
            {
                $response = $this->http->setUri($source)->send();
                
                if($response->isSuccess())
                {
                    $code = $response->getBody();
                    
                    $this->cache->setItem($key, $code);
                }
            }        
        }
        
        return $code;
    }
    
    /**
     * Parse Subversion tags using Trac browser
     */
    public function loadTags()
    {
        // tag list from Trac repository browser
        $crawler = new Crawler($this->fetch('tags'));
        
        // each table row is a tag
        $rows = $crawler->filter('#dirlist tr');
        foreach($rows as $index=>$node)
        {
            $row = $rows->eq($index);
            if($row->filter('a.dir')->count())
            {
                // created datetime obtained from "age" link
                $time = $row->filter('a.timeline')->attr('title');
                $time = trim(preg_replace('/See timeline at/', '', $time));
                
                // tag object
                $tag = new Tag();
                $tag->name = trim($row->filter('.name')->text());  
                $tag->revision = trim($row->filter('.rev a')->first()->text());
                $tag->description = trim($row->filter('.change')->text());
                $tag->created = Carbon::parse($time);
                
                // fixes to tag name
                $tag->name = preg_replace('/^v/', '', $tag->name);
                
                $this->tags[$tag->name] = $tag;
            }
        }
    }
    
    /**
     * Parse public releases using "changelog" tab on profile
     */
    protected function loadReleases()
    {
        // tags need to be loaded before parse releases
        $this->loadTags();
        
        // profile 
        $crawler = new Crawler($this->fetch('profile'));

        // plugin title (used for feed title)
        $this->title = $crawler->filter('#plugin-title h2')->text();
        $this->title = preg_replace('/\s*(:|\s+\-|\|)(.+)/', '', $this->title);
        $this->title = preg_replace('/\s+\((.+)\)$/', '', $this->title);
        
        // short description
        $this->description = $crawler->filter('.shortdesc')->text();

        // need to parse changelog block
        $changelog = $crawler->filter('.block.changelog .block-content');
        
        // each h4 is a release
        foreach($changelog->filter('h4') as $index=>$node)
        {
            // convert release title to version
            $version = $this->parseVersion($node->textContent);
            
            // version must exist in tag list
            if(!isset($this->tags[$version]))
            {
                continue;
            }
            
            /* @var $tag Tag */
            $tag =& $this->tags[$version];

            // release object
            $release = new Release();
            $release->title = "{$this->title} $version";
            $release->description = $tag->description;
            $release->stability = $this->parseStability($node->textContent);
            $release->created = $tag->created;
            $release->content = '';

            // nodes that follows h4 are the details
            $details = $changelog->filter('h4')->eq($index)->nextAll();
            foreach($details as $index=>$node)
            {
                if($node->tagName != 'h4')
                {
                    $release->content .= "<{$node->tagName}>" . 
                                         $details->eq($index)->html() .
                                         "</{$node->tagName}>" . PHP_EOL;
                }
                else
                {
                    break;
                }
            }

            // use tag description if no content is detected
            if(empty($release->content))
            {
                $release->content = $tag->description;
            }

            $this->releases[$version] = $release;
        }
        
        // with zero releases, generate release data from Trac
        if(empty($this->releases))
        {
            foreach($this->tags as $tag)
            {
                $version = $tag->name;
                
                $release = new Release();
                $release->title = "{$this->title} $version";
                $release->description = $tag->description;
                $release->stability = $this->parseStability($tag->name);
                $release->created = $tag->created;
                $release->content = "Commit message: " . $tag->description;

                $this->releases[$version] = $release;
            }
            
            reset($this->tags);            
        }
        
        // add extra info to detected releases
        foreach($this->releases as $version=>$release)
        {            
            // tag instance
            $tag =& $this->tags[$version];
            
            // sets the feed modification time
            if(is_null($this->modified))
            {
                $this->modified = $tag->created;
            }
            
            // link to Track browser listing commits between since previous tag
            $release->link = 'https://plugins.trac.wordpress.org/log/'
                    . $this->plugin . '/trunk?action=stop_on_copy'
                    . '&mode=stop_on_copy&rev=' . $tag->revision 
                    . '&limit=100&sfp_email=&sfph_mail=';                       
            
            // move pointer to previous release
            while(current($this->tags) && key($this->tags) != $version)
            {
                next($this->tags);
            }
            
            // add previous release revision to limit commit list
            $previous = next($this->tags);
            if(!empty($previous))
            {
                $release->link .= '&stop_rev=' . $previous->revision;
            }
            
            $this->releases[$version] = $release;
        }
    }
    
    /**
     * Parses a string containing version to extract it
     * 
     * @param   string  $string
     * @return  string
     */
    protected function parseVersion($string)
    {
        $version = false;
        
        $string = preg_replace("/^{$this->title}\s+/i", '', $string);
        $string = preg_replace('/^v(er)?(sion\s*)?/i', '', trim($string));

        if(preg_match('/(\d|\.)+/', $string, $match))
        {
            $version = $match[0];
        }                

        return $version;
    }
    
    /**
     * Parses a string containing version to extract its type (alpha, beta...)
     * 
     * @param   string  $string
     * @return  strign
     */
    protected function parseStability($string)
    {
        $stability = 'stable';
        
        $versions = array
        (
            'alpha' => "/(alpha)(\s*\d+)?/i",
            'beta' => "/(beta)(\s*\d+)?/i",
            'rc' => "/(rc|release\s+candidate)(\s*\d+)?/i",
        );
        
        foreach($versions as $version=>$regexp)
        {
            if(preg_match($regexp, $string, $match))
            {
                $stability = $version;
                
                if(!empty($match[2]))
                {
                    $stability .= '.' . trim($match[2]);
                }
            }
        }
        
        return $stability;
    }
    
    /**
     * Get the parsed releases applying filters
     * 
     * @return  array
     */
    public function getReleases($limit = null)
    {
        if(is_null($limit))
        {
            $limit = getenv('OUTPUT_LIMIT') ?: 25;
        }

        array_walk($this->releases, function(Release $release)
        {
            $release->filter();
        });

        return $limit ? array_slice($this->releases,0,$limit) : $this->releases;
    }
    
    /**
     * Error handler
     * 
     * @param   int     $errno
     * @param   string  $errstr
     */
    public function error($errno, $errstr, $errfile, $errline, $errcontext)
    {
        if($this->cli === false)
        {
            header('HTTP/1.1 500');
            echo "<h1>Error $errno</h1>";
            echo "<p><strong>Plugin:</strong> {$this->plugin}<br />";
            echo "<strong>Message:</strong> $errstr<br />";
            echo "<strong>File:</strong> $errfile ($errline)</p>";
            exit;
        }
    }

    /**
     * Exception handler
     * 
     * @param Exception $exception
     */
    public function exception(Exception $exception)
    {
        $this->error
        (
            $exception->getCode(), 
            $exception->getMessage(), 
            $exception->getFile(), 
            $exception->getLine(), 
            $exception->getTrace()
        );
    }
}