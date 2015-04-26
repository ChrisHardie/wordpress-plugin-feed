<?php

use Carbon\Carbon;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Slider Revolution custom parser
 *
 * @author David Martínez <contacto@davidmartinez.net>
 */
class RevolutionSliderFeed extends WordPressPluginFeed
{
    /**
     * Plugin image
     * 
     * @var string
     */
    protected $image = 'https://0.s3.envato.com/files/104347001/smallicon2.png';
    
    /**
     * Source URLs 
     *
     * @var array
     */    
    protected $sources = 
    [
        'profile'   => 'http://codecanyon.net/item/slider-revolution-responsive-wordpress-plugin/2751380',
    ];
    
    /**
     * Parse public releases using "release log" block on Code Canyon profile
     */    
    protected function loadReleases()
    {
        // profile 
        $crawler = new Crawler($this->fetch('profile'));
        
        // plugin title (used for feed title)
        $this->title = 'Slider Revolution';
        
        // short description
        $description = $crawler->filter('.item-description h3')->nextAll();
        foreach($description as $index=>$node)
        {
            if($node->tagName != 'h4')
            {
                $this->description .= $description->eq($index)->text();
            }
            else
            {
                break;
            }
        }
        
        // need to parse changelog block
        $changelog = $crawler->filter('img')->reduce(function($node, $index)
        {
            return (bool) preg_match('/tpbanner_updates/', $node->attr('src'));
        })->parents()->nextAll();
        
        // each h4 is a release
        foreach($changelog->filter('h3') as $index=>$node)
        {
            // convert release title to version
            $version = $node->textContent;
            $version = preg_replace('/^v(ersion\s*)?/i', '', trim($version));
            $version = preg_replace('/\s+(.+)$/', '', trim($version));
            
            // title must have pubdate
            if(!preg_match('/(.+) \((.+)\)/i', $node->textContent, $pubdate))
            {
                continue;
            }
            // release object
            $release = new stdClass();
            $release->link = $this->sources['profile'];
            $release->title = "{$this->title} $version";
            $release->description = false;
            $release->created = time();
            $release->content = '';

            // nodes that follows h4 are the details
            $details = $changelog->filter('h3')->eq($index)->nextAll();
            foreach($details as $index=>$node)
            {
                if($node->tagName != 'h3')
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
            
            // pubdate needs to be parsed
            $release->created = $this->modified = Carbon::parse($pubdate[2]);
            
            $this->releases[$version] = $release;
        }
    }
}
