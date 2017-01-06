<?php

class Crawler
{
    protected $curl;

    protected function http($url, $post_params = null)
    {
        for ($i = 0; $i < 3; $i ++) {
            curl_setopt($this->curl, CURLOPT_URL, $url);
            if (!is_null($post_params)) {
                $postfields = implode('&', array_map(function($k) use ($post_params) {
                    return urlencode($k) . '=' . urlencode($post_params[$k]);
                }, array_keys($post_params)));
                curl_setopt($this->curl, CURLOPT_POSTFIELDS, $postfields);
            } else {
                curl_setopt($this->curl, CURLOPT_POSTFIELDS, null);
            }
            $content = curl_exec($this->curl);
            $info = curl_getinfo($this->curl);
            if ($info['http_code'] != 200) {
                if ($i == 2) {
                    throw new Exception("抓取 {$url} 失敗, code = {$info['http_code']}");
                }
                error_log("抓取 {$url} 失敗, code = {$info['http_code']}，等待 10 秒後重試");
                sleep(10);
                continue;
            }
            break;
        }
        return $content;
    }

    public function crawlCategory()
    {
        error_log("抓取法案分類清單");

        $categories = array();

        $content = $this->http('http://lis.ly.gov.tw/lglawc/lglawkm');
        $doc = new DOMDocument;
        @$doc->loadHTML($content);

        $category_link = null;

        // 找到分類瀏覽網址
        foreach ($doc->getElementsByTagName('img') as $img_dom) {
            if ($img_dom->getAttribute('alt') == '分類瀏覽') {
                $category_link = $img_dom->parentNode->getAttribute('href');
                break;
            }
        }

        if (is_null($category_link)) {
            throw new Exception("從 http://lis.ly.gov.tw/lglawc/lglawkm 找不到分類瀏覽網址");
        }

        $content = $this->http('http://lis.ly.gov.tw' . $category_link);
        $doc = new DOMDocument;
        @$doc->loadHTML($content);

        foreach ($doc->getElementsByTagName('div') as $div_dom) {
            if ($div_dom->getAttribute('class') == 'title') {
                $category = $div_dom->nodeValue;
                foreach ($div_dom->previousSibling->getElementsByTagName('a') as $a_dom) {
                    $href = $a_dom->getAttribute('href');
                    if (strpos($href, '/lglawc/lglawkm') === false) {
                        continue;
                    }
                    preg_match('#(.*)\((\d+)\)#', $a_dom->nodeValue, $matches);
                    $title = $matches[1];
                    $count = $matches[2];
                    $categories[] = array($category, $title, $href, $count);
                }
            }
        }
        return $categories;
    }

    public function getLawCategories()
    {
        if (!file_exists('laws-category.csv')) {
            return array();
        }

        $law_categories = array();

        $fp = fopen('laws-category.csv', 'r');
        $columns = fgetcsv($fp);
        while ($rows = fgetcsv($fp)) {
            list($c1, $c2, $status, $law_id) = $rows;
            $law_categories[implode(',', array($c1, $c2, $law_id))] = $rows;
        }
        fclose($fp);
        return $law_categories;
    }

    /**
     * getNextPageDoc 從現在這一頁的 $doc 找出下一頁的內容，如果沒有下一頁傳 null
     * 
     * @param mixed $doc 
     * @access public
     * @return void
     */
    public function getNextPageDoc($doc)
    {
        // 看看有沒有下一頁
        if (!$form_dom = $doc->getElementsByTagName('form')->item(0)) {
            return null;
        }
        $has_next_input = false;
        $params = array();
        foreach ($form_dom->getElementsByTagName('input') as $input_dom) {
            if ($input_dom->getAttribute('type') == 'image' and $input_dom->getAttribute('src') == '/lglaw/images/page_next.png') {
                $has_next_input = true;
                $params[$input_dom->getAttribute('name') . '.x'] = 5;
                $params[$input_dom->getAttribute('name') . '.y'] = 5;
            } elseif ($input_dom->getAttribute('type') == 'hidden') {
                $params[$input_dom->getAttribute('name')] = $input_dom->getAttribute('value');
            }
        }

        if (!$has_next_input) {
            return null;
        }

        $url = 'http://lis.ly.gov.tw' . $form_dom->getAttribute('action');

        $content = $this->http($url, $params);
        $doc = new DOMDocument;
        @$doc->loadHTML($content);
        return $doc;
    }

    public function crawlLawList($categories)
    {
        // 先與現在的 laws.csv 比較一下數量
        $category_count = array();
        foreach (self::getLawCategories() as $law_category) {
            list($category, $category2 ) = $law_category;
            if (!array_key_exists($category . '-' . $category2, $category_count)) {
                $category_count[$category . '-' . $category2] = 0;
            }
            $category_count[$category . '-' . $category2] ++;
        }

        $count = count($categories);
        foreach ($categories as $no => $rows) {
            list($category, $category2, $url, $count) = $rows;
            if (array_key_exists($category . '-' . $category2, $category_count) and $category_count[$category . '-' . $category2] == $count) {
                error_log("{$category} / {$category2} 數量吻合，不需要重抓");
                continue;
            }

            $laws = array();
            error_log("抓取法案 {$no}/{$count} {$category} > {$category2} 分類法案");

            $url = 'http://lis.ly.gov.tw' . $url;
            $content = $this->http($url);
            $doc = new DOMDocument;
            @$doc->loadHTML($content);

            // 先把廢止法連結拉出來備用
            $other_links = array();
            foreach ($doc->getElementsByTagName('a') as $a_dom) {
                if (strpos($a_dom->nodeValue, '廢止法') === 0) {
                    $other_links['廢止'] = $a_dom->getAttribute('href');
                } elseif (strpos($a_dom->nodeValue, '停止適用法') === 0) {
                    $other_links['停止適用'] = $a_dom->getAttribute('href');
                }
            }

            // 先爬現行法
            while (true) {
                foreach ($doc->getElementsByTagName('a') as $a_dom) {
                    if (strpos($a_dom->getAttribute('href'), '/lglawc/lawsingle') === 0) {
                        for ($i = 0; $i < 3; $i ++) {
                            try {
                                $this->crawlLaw($category, $category2, "現行", $a_dom->nodeValue, $a_dom->getAttribute('href'));
                                break;
                            } catch (Exception $e) {
                                error_log("crawlLaw {$a_dom->nodeValue} failed, retry");

                            }
                        }
                    }
                }

                if (!$doc = self::getNextPageDoc($doc)) {
                    break;
                }
            }

            // 再爬其他法
            foreach ($other_links as $other_type => $other_link) {
                $url = 'http://lis.ly.gov.tw' . $other_link;
                error_log($url);
                $content = $this->http($url);
                $doc = new DOMDocument;
                @$doc->loadHTML($content);
                while (true) {
                    foreach ($doc->getElementsByTagName('a') as $a_dom) {
                        if (strpos($a_dom->getAttribute('href'), '/lglawc/lawsingle') === 0) {
                            $this->crawlLaw($category, $category2, $other_type, $a_dom->nodeValue, $a_dom->getAttribute('href'));
                        }
                    }

                    if (!$doc = self::getNextPageDoc($doc)) {
                        break;
                    }
                }
            }
        }
    }

    public function crawlLaw($category, $category2, $status, $title, $url)
    {
        error_log("抓取條文 {$category} > {$category2} > {$title} ({$status}) 資料");

        $url = 'http://lis.ly.gov.tw' . $url;
        $content = $this->http($url);
        $doc = new DOMDocument;
        @$doc->loadHTML($content);

        // 先取出法條 ID
        $law_id = null;
        foreach ($doc->getElementsByTagName('td') as $td_dom) {
            if ($td_dom->getAttribute('class') == 'law_NA' and preg_match('#\((\d+)\)$#', trim($td_dom->nodeValue), $matches)) {
                $law_id = $matches[1];
                break;
            }
        }
        if (!file_exists(__DIR__ . "/laws/{$law_id}")) {
            mkdir(__DIR__ . "/laws/{$law_id}");
        }


        if (is_null($law_id)) {
            throw new Exception("從 $url 找不到法條代碼");
        }

        foreach ($doc->getElementsByTagName('td') as $td_dom) {
            if ($td_dom->getAttribute('class') !== 'version_0') {
                continue;
            }
            $versions = array($td_dom->getElementsByTagName('a')->item(0)->nodeValue);
            $law_url = $td_dom->getElementsByTagName('a')->item(0)->getAttribute('href');
            while ($td_dom = $td_dom->parentNode->nextSibling) {
                $versions[] = $td_dom->nodeValue;
            }

            // 先抓全文 
            if (!file_exists(__DIR__ . "/laws/{$law_id}/{$versions[0]}.html")) {
                $url = 'http://lis.ly.gov.tw' . $law_url;
                $content = $this->http($url);
                file_put_contents(__DIR__ . "/laws/{$law_id}/{$versions[0]}.html", $content);
            } else {
                $content = file_get_contents(__DIR__ . "/laws/{$law_id}/{$versions[0]}.html");
            }

            // 再從全文中找出異動條文和理由及歷程
            $law_doc = new DOMDocument;
            @$law_doc->loadHTML($content);
            $btn_map = array(
                'yellow_btn01' => '異動條文及理由',
                'yellow_btn02' => '立法歷程',
                'yellow_btn03' => '異動條文',
                'yellow_btn04' => '廢止理由',
                'yellow_btn05' => '新舊條文對照表',
            );
            $types = array();
            foreach ($law_doc->getElementsByTagName('img') as $img_dom) {
                foreach ($btn_map as $id => $name) {
                    if ($img_dom->getAttribute('src') == "/lglaw/images/{$id}.png"){
                        $url = 'http://lis.ly.gov.tw' . $img_dom->parentNode->getAttribute('href');
                        $content = $this->http($url);
                        file_put_contents(__DIR__ . "/laws/{$law_id}/{$versions[0]}-{$name}.html", $content);

                        $types[] = $name;
                    }
                }
            }
            self::addLawVersion($law_id, $title, $versions, $types);
        }

        self::addLaw($category, $category2, $status, $law_id, $title);
    }

    public static function addLawVersion($law_id, $title, $versions, $types)
    {
        $law_versions = array();

        if (file_exists('laws-versions.csv')) {
            $fp = fopen('laws-versions.csv', 'r');
            $columns = fgetcsv($fp);

            while ($rows = fgetcsv($fp)) {
                $law_versions[$rows[0] . '-' . $rows[2]] = $rows;
            }
            fclose($fp);
        }

        $versions = implode(';', $versions);
        $types = implode(';', $types);
        $law_versions[$law_id . '-' . $versions] = array(
            $law_id, $title, $versions, $types,
        );

        uksort($law_versions, function($k1, $k2) {
            $k1 = array_shift(explode(';', $k1));
            preg_match('#^(\d+)-中華民國(\d+)年(\d+)月(\d+)日#', $k1, $matches);
            $k1 = sprintf("%05d%03d%02d%02d", $matches[1], $matches[2], $matches[3], $matches[4]);

            $k2 = array_shift(explode(';', $k2));
            preg_match('#^(\d+)-中華民國(\d+)年(\d+)月(\d+)日#', $k2, $matches);
            $k2 = sprintf("%05d%03d%02d%02d", $matches[1], $matches[2], $matches[3], $matches[4]);
            return strcmp($k1, $k2);
        });

        $fp = fopen('laws-versions.csv', 'w');
        fputcsv($fp, array('代碼', '法條名稱', '發布時間', '包含資訊'));
        foreach ($law_versions as $rows) {
            fputcsv($fp, $rows);
        }
        fclose($fp);

           
    }

    public static function addLaw($category, $category2, $status, $law_id, $title)
    {
        $laws = array();
        if (file_exists('laws.csv')) {
            $fp = fopen('laws.csv', 'r');
            fgetcsv($fp);
            while ($rows = fgetcsv($fp)) {
                $laws[$rows[0]] = $rows;
            }
            fclose($fp);
        }

        $laws[$law_id] = array($law_id, $title, $status);
        ksort($laws);

        $fp = fopen('laws.csv', 'w');
        fputcsv($fp, array('代碼', '法條名稱', '狀態'));
        foreach ($laws as $k => $v) {
            fputcsv($fp, $v);
        }
        fclose($fp);

        $law_categories = self::getLawCategories();
        $rows = array($category, $category2, $title);
        $law_categories[implode(',', array($category, $category2, $law_id))] = array(
            $category, $category2, $status, $law_id, $title
        );
        ksort($law_categories);

        $fp = fopen('laws-category.csv', 'w');
        fputcsv($fp, array('主分類', '次分類', '狀態', '代碼', '法條名稱'));
        foreach ($law_categories as $rows) {
            fputcsv($fp, $rows);
        }
        fclose($fp);
    }

    public function main()
    {
        if (!file_exists(__DIR__ . '/laws')) {
            mkdir(__DIR__ . '/laws');
        }

        $this->curl = curl_init();
        curl_setopt($this->curl, CURLOPT_COOKIEFILE, '');
        curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, true);

        $categories = $this->crawlCategory();
        $this->crawlLawList($categories);
    }
}

$c = new Crawler;
$c->main();
