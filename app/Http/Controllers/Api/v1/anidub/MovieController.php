<?php

namespace App\Http\Controllers\Api\v1\anidub;

use App\Helpers;
use App\Helpers\Parser;
use App\Http\Controllers\Controller;
use App\Http\Requests;
use App\Movie;
use Cache;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Request;
use Underscore\Types\Arrays;
use Yangqi\Htmldom\Htmldom;

class MovieController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @param int $page
     * @return \Illuminate\Http\JsonResponse
     */
    public function page($page = 1)
    {
        // generate key
        $path = Request::input('path');
        $search_query = Request::input('q');

        $key = 'anidub_page_' . $page; // ex. anidub_page_1
        if (isset($path)) {
            $key = 'anidub_' . str_replace('/', '_', $path) . '_page_' . $page; //ex. anidub__anime-rus_tv-rus__page_1
        } else if (isset($search_query)) {
            $key = 'anidub_' . md5($search_query) . '_page_' . $page; //ex. anidub_34jhg234876sdfsjknk98_page_1
        }
        $items = [];
        // get page or cache
        try {
            if (isset($search_query)) {
                $cachedHtml = $this->getCachedSearch($key, $page, $search_query);
            } else {
                $cachedHtml = $this->getCachedPage($key, $page, $path);
            }
        } catch (ClientException $e) {
            $response = $e->getResponse();
            if ($response->getStatusCode() == 404) {
                return response()->json(array(
                    'status' => $response->getReasonPhrase(),
                    'page' => (int)$page,
                    'movies' => $items
                ), 200);
            }
        }
        $html = new Htmldom($cachedHtml);
        // parse html
        foreach ($html->find('#dle-content .news_short') as $element) {
            if ($element->find('.news', 0)) {
                //dd($element->innertext);
                if ($element->find('.news ul.reset li span a', 0) &&
                    $element->find('.poster_img img', 0) && strlen($element->find('.poster_img img', 0)->alt) > 0
                ) {

                    $id = mb_split('-', $element->find('div[id^=news-id]', 0)->id)[2];
                    $data_original = 'data-original';
                    $title = $element->find('.poster_img img', 0)->alt;
                    $date = '';//$element->find('.headinginfo .date a', 0)->plaintext;
                    $comment_count = trim(mb_split(':', $element->find('.newsfoot li', 0)->plaintext)[1]);

                    if (isset($search_query)) {
                        $image_original = $element->find('.poster_img img', 0)->src;
                    } else {
                        preg_match("/data-original=\"(.*)\"/iU", $element->find('.poster_img', 0)->innertext, $output_posters);
                        $image_original = (isset($output_posters[1])) ? $output_posters[1] : '';
                    }
                    $image_small = str_replace('/poster/', '/poster/small/', $image_original);

                    $description = $element->find('div[id^=news-id]', 0)->plaintext;

                    // year
                    $year = $element->find('.news ul li span a', 0)->plaintext;
                    //production
                    preg_match("/<b>Страна: <\\/b><span>(.*)<\\/span>/iU", $element->find('.news ul', 0)->innertext, $output_production);
                    // series count
                    preg_match("/<b>Количество серий: <\\/b><span>(.*)<\\/span>/iU", $element->find('.news ul', 0)->innertext, $output_series);
                    // gerne
                    $genres = [];
                    foreach ($element->find('span[itemprop="genre"] a') as $item) {
                        $genres[] = $item->plaintext;
                    }
                    //aired
                    preg_match("/<b>Дата выпуска: <\\/b><span>(.*)<\\/span>/iU", $element->find('.news ul', 0)->innertext, $output_aired);
                    // producers
                    preg_match("/<b>Режиссёр<\\/b>(.*)<br/iU", $element->find('.news ul', 0)->innertext, $output_producers);
                    $producers = [];
                    foreach ($element->find('li[itemprop="director"] span a') as $item) {
                        $producers[] = $item->plaintext;
                    }
                    // author
                    $authors = [];
                    foreach ($element->find('li[itemprop="author"] span a') as $item) {
                        $authors[] = $item->plaintext;
                    }
                    //postscoring
                    $output_postscoring = array();
                    preg_match("/<b>Озвучивание: <\\/b><span>(.*)<\\/span>/iU", $element->find('.news ul', 0)->innertext, $output_postscoring_tmp);
                    if (isset($output_postscoring_tmp[1])) {
                        preg_match_all("/<a.*>(.*)<\\/a>/iU", $output_postscoring_tmp[1], $output_postscoring);
                    }
                    // studio
                    $studio = $element->find('.video_info a img', 0) ? $element->find('.video_info a img', 0)->alt : false;
                    // get movie from db
                    $movie = Movie::firstOrCreate(['movie_id' => $id]);
                    $movie->movie_id = $id;
                    $movie->description = $description;
                    $movie->title = $title;
                    $movie->service = 'anidub';
                    $info = array(
                        'published_at' => $date,
                        'images' => array(
                            'thumbnail' => $image_small,
                            'original' => $image_original
                        ),
                        'year' => $year,
                        'production' => (isset($output_production[1])) ? trim($output_production[1]) : '',
                        'genres' => $genres,
                        'series' => (isset($output_series[1])) ? trim($output_series[1]) : '',
                        'aired' => (isset($output_aired[1])) ? trim($output_aired[1]) : '',
                        'producers' => $producers,
                        'authors' => $authors,
                        'postscoring' => (isset($output_postscoring[1])) ? $output_postscoring[1] : [],
                        'studio' => $studio,
                        'online' => true,
                        'torrent' => false
                    );
                    $info['comments']['count'] = $comment_count;
                    // merge infos
                    $movie->info = array_merge((array)$movie->info, $info);
                    $movie->save();
                    array_push($items, $movie);
                }
            }
        }

        $html->clear();
        unset($html);

        return response()->json(array(
            'status' => 'success',
            'page' => (int)$page,
            'movies' => $items
        ), 200);
    }

    /**
     * Get description page
     *
     * @param $movieId
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($movieId)
    {
        // get page from cache
        $cachedHtml = $this->getCachedFullPage('anidub_show_' . $movieId, $movieId);
        $html = new Htmldom($cachedHtml);

        if ($html->find('.infocon strong', 0)) {
            if ($html->find('.infocon strong', 0)->plaintext == "Внимание, обнаружена ошибка") {
                $movie = Movie::firstOrCreate(['movie_id' => $movieId]);

                // hack: get info from anidub api
                $client = new Client();
                $response = $client->get(config('api.api_anidub_info_url') . $movieId);
                $json = json_decode($response->getBody(true),false);

                $files = array();
                foreach($json->Responce->Episodes as $episode){
                    $file_item = array(
                        'service' => Parser::getVideoService($episode->Url),
                        'part' => $episode->Number,
                        'original_link' => $episode->Url,
                        'download_link' => Parser::createDownloadLink($episode->Url)
                    );
                    array_push($files, $file_item);
                }


                $movie->title = trim($json->Responce->Title);
                $movie->description = trim($json->Responce->Information->Description);

                $info = is_object($movie->info) ? $movie->info : new \stdClass();
                $info->screenshots = array();
                $info->series = $json->Responce->Information->Episodes;
                $info->production = $json->Responce->Information->Country;
                $info->postscoring = explode(', ',$json->Responce->Information->Duber);
                $info->files = $files;
                $movie->info = $info;
                $movie->save();

                return response()->json(array(
                    'status' => 'auth_needed',
                    'movie' => $movie,
                ), 200);
            }
        }

        //description
        $html->find('div[itemprop="description"] div[id^="news-id-"]', 0)->outertext = '';
        $html->find('div[itemprop="description"] div[id^="news-id-"]', 0)->innertext = '';
        $html->save();
        $description = $html->find('div[itemprop="description"]', 0)->plaintext;
        $description = str_replace('Описание:', '', $description);
        $description = str_replace('Справка', '', $description);

        //screenshots
        $screenshots = array();
        foreach ($html->find('.screens a[onclick="return hs.expand(this)"]') as $screen) {
            $screen_item = array(
                'thumbnail' => $screen->find('img', 0)->src,
                'original' => $screen->href
            );
            array_push($screenshots, $screen_item);
        }


        //load movie from db
        $movie = Movie::firstOrCreate(['movie_id' => $movieId]);
        $movie->title = trim($html->find('h1.titlfull', 0)->plaintext);
        $movie->description = trim(nl2br($description));

        $info = is_object($movie->info) ? $movie->info : new \stdClass();
        $info->screenshots = $screenshots;
        $movie->info = $info;
        $movie->save();

        $html->clear();
        unset($html);

        return response()->json(array(
            'status' => 'success',
            'movie' => $movie,
        ), 200);
    }

    /**
     * Show files list
     *
     * @param $movieId
     * @return \Illuminate\Http\JsonResponse
     */
    public function files($movieId){
        // get page from cache
        $cachedHtml = $this->getCachedFullPage('anidub_show_' . $movieId, $movieId);
        $html = new Htmldom($cachedHtml);
        //files
        $files = array();
        $findedFiles = $html->find('select[id^=sel] option');
        if(count($findedFiles) > 1){
            $fileItems = $findedFiles;
        }else{
            $fileItems = $html->find('.players iframe');
        }
        foreach ($fileItems as $file) {
            if(count($findedFiles) > 1) {
                $part = $file->plaintext;
                $link = $file->value;
            }else{
                $part = trim(mb_split('/',$html->find('title',0)->plaintext)[0]);
                $link = $file->src;
            }
            //fix vk link
            $link = explode('|', $link)[0];
            $link = str_replace('pp.anidub-online.ru/video_ext.php', 'vk.com/video_ext.php', $link);

            $download_link = $link;
            $videoService = Parser::getVideoService($link);

            if($videoService !== 'sibnet' && $videoService !== 'moonwalk'){
                $download_link = Parser::createDownloadLink($link);
            }

            if($videoService === 'moonwalk'){
                $download_link = url('api/v1/moonwalk.m3u8?url='.$link);
            }

            $file_item = array(
                'service' => $videoService,
                'part' => $part,
                'original_link' => $link,
                'download_link' => $download_link
            );
            array_push($files, $file_item);
        }
        $grouped_files_ = Arrays::group($files, function ($value) {
            return $value['part'];
        });
        $grouped_files = Arrays::values($grouped_files_);

        return response()->json($grouped_files, 200);
    }

    /**
     * Get comments list with limit
     *
     * @param $movieId
     * @return \Illuminate\Http\JsonResponse
     */
    public function comments($movieId)
    {
        $comments = array();

        $cachedHtml = $this->getCachedFullPage('anidub_show_' . $movieId, $movieId);
        $html = new Htmldom($cachedHtml);
        // create comment url
        $latest_page = ($html->find('.dle-comments-navigation .navigation a', -1) !== null) ? $html->find('.dle-comments-navigation .navigation a', -1)->innertext : null;

        if ($html->find('.infocon strong', 0)) {
            if ($html->find('.infocon strong', 0)->plaintext == "Внимание, обнаружена ошибка") {
                $movie_tmp = Movie::firstOrCreate(['movie_id' => $movieId]);
                $json = json_decode($movie_tmp->toJson());
                $latest_page = (int)((int)$json->info->comments->count / 10);
            }
        }

        //clear html
        $html->clear();
        unset($html);

        //fetch all comments pages
        $n = $latest_page ? $latest_page : 1;
//        for ($i = 1; $i <= $n; $i++) {
        $index=0; // index for page count
        for ($i = $n; $i > 0; $i--) {
            ++$index;
            if($index > config('api.comment_page_limit')) continue;
            $url = sprintf('http://online.anidub.com/engine/ajax/comments.php?cstart=%d&news_id=%d&skin=Anidub_online', $i, $movieId);

            $response_json = Cache::remember(md5($url), env('PAGE_CACHE_MIN'), function () use ($url) {
                $client = new Client();
                $response = $client->get($url);
                $responseUtf8 = mb_convert_encoding($response->getBody(true), 'utf-8', 'auto');
                $response_json = json_decode($responseUtf8, true);
                return $response_json;
            });
            //parse comment page
            $html = new Htmldom($response_json['comments']);
            foreach ($html->find('div[id^=comment-id]') as $comment_item) {
                $tmpId = explode('-', $comment_item->id);
                $commentId = array_pop($tmpId);

                $body = $comment_item->find('div[id^=comm-id]', 0)->innertext;
                $body_text = $comment_item->find('div[id^=comm-id]', 0)->plaintext;
                $body_text = str_replace('&nbsp;Комментарий скрыт в связи с низким рейтингом', '', $body_text);
                $comment = array(
                    'comment_id' => $commentId,
                    'date' => $comment_item->find('.comm_inf ul>li', 0)->plaintext,
                    'author' => $comment_item->find('.comm_title a', 0)->plaintext,
                    'body' => trim($body_text),
                    'avatar' => $comment_item->find(".commcont center > img", 0)->src
                );
                array_push($comments, $comment);
            }
        }

        //get movie from db
        $movie = Movie::firstOrCreate(['movie_id' => $movieId]);
        /*$info = is_object($movie->info) ? $movie->info : new \stdClass();
        $info->comments = isset($info->comments) ? $info->comments : new \stdClass();
        $info->comments->list = $comments;
        $movie->info = $info;
        $movie->save();*/

        return response()->json(array(
            'status' => 'success',
            'count' => $movie->info->comments->count,
            'list' => $comments,
        ), 200);
    }


    /**
     * Get cached page
     *
     * @param string $cache_key Unique key for cache
     * @param integer $page Page to parse
     * @return mixed response
     */
    private function getCachedPage($cache_key, $page, $path)
    {
        return Cache::remember($cache_key, env('PAGE_CACHE_MIN'), function () use ($page, $path) {
            $url = isset($path) ? urldecode($path) . 'page/' . $page . '/' : '/page/' . $page . '/';
            $client = new Client(array(
                'base_uri' => env('BASE_URL_ANIDUB')
            ));
            $response = $client->get($url);
            $responseUtf8 = mb_convert_encoding($response->getBody(true), 'utf-8', 'auto');
            unset($client);
            return $responseUtf8;
        });
    }

    /**
     * Get cached search
     *
     * @param string $cache_key Unique key for cache
     * @param integer $page Page to parse
     * @param string $search_query search query
     * @return mixed response
     */
    private function getCachedSearch($cache_key, $page, $search_query)
    {
        return Cache::remember($cache_key, env('PAGE_CACHE_MIN'), function () use ($page, $search_query) {
            $client = new Client(array(
                'base_uri' => env('BASE_URL_ANIDUB')
            ));
            $result_from = ((int)$page * 15 - 15) + 1;
            $response = $client->post("/index.php?do=search", [
                'form_params' => [
                    'do' => 'search',
                    'subaction' => 'search',
                    'full_search' => 0,
                    'search_start' => $page,
                    'result_from' => ($page == 1) ? 1 : $result_from,
                    'story' => rawurlencode($search_query)//mb_convert_encoding($search_query,'cp1251','utf-8')
                ]
            ]);
            $responseUtf8 = mb_convert_encoding($response->getBody(true), 'utf-8', 'auto');
            //dd($responseUtf8);
            unset($client);
            return $responseUtf8;
        });
    }

    /**
     * Get description page
     *
     * @param string $cache_key Unique key for cache
     * @param integer $movieId Page to parse
     * @return mixed response
     */
    private function getCachedFullPage($cache_key, $movieId)
    {
        return Cache::remember($cache_key, env('PAGE_CACHE_MIN'), function () use ($movieId) {
            $client = new Client(array(
                'base_uri' => env('BASE_URL_ANIDUB')
            ));
            $response = $client->get('/index.php?newsid=' . $movieId);
            $responseUtf8 = mb_convert_encoding($response->getBody(true), 'utf-8', 'auto');
            unset($client);
            return $responseUtf8;
        });
    }


}
