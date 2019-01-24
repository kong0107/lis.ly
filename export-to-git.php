<?php

require_once('config.php');

class Error2 extends Exception{
    public function setMessage($message){
        $this->message = $message;
    }
}


class Exporter
{
    public function trim($str)
    {
        return trim($str, " \t\n" . html_entity_decode('&nbsp;'));
    }

    public function genContent($origin_obj, $laws)
    {
        $obj = clone $origin_obj;
        unset($obj->types); // types 用不到，跳過

        $ret = new StdClass;
        $ret->content = '';
        foreach ($obj->law_data as $record) {
            if (property_exists($record, 'section_name')) {
                $title = $record->section_name;
                $ret->content .= $title . "\n";
                $ret->content .= str_repeat('=', mb_strwidth($title)) . "\n";
                continue;
            }

            if (!property_exists($record, 'rule_no')) {
                print_r($record);
                exit;
            }
            $title = $record->rule_no;
            if (property_exists($record, 'note')) {
                $title .= " " . $record->note;
            }
            $ret->content .= $title . "\n";
            $ret->content .= str_repeat('-', mb_strwidth($title)) . "\n";
            $ret->content .= str_replace("\n", "  \n", $record->content) . "  \n";

            if (property_exists($obj, 'law_reasons') and array_key_exists($record->rule_no, $obj->law_reasons)) {
                $ret->content .= "> 理由：" . str_replace("\n", "\n\n> ", trim($obj->law_reasons->{$record->rule_no})) . "\n\n";
                //unset($obj->law_reasons->{$record->rule_no});
            }

            if (property_exists($record, 'relates') and is_object($record->relates)) {
                foreach (array('引用條文', '被引用條文') as $type) {
                    if (property_exists($record->relates, $type)) {
                        foreach ($record->relates->{$type} as $relate_law) {
                            if (!array_key_exists($relate_law->law_no, $laws)) {
                                throw new Exception("找不到 {$relate_law->law_no}");
                            }
                            $relate_url = sprintf("../../%s/%s/%s.md",
                                $laws[$relate_law->law_no]['categories'][0][0],
                                $laws[$relate_law->law_no]['categories'][0][1],
                                $laws[$relate_law->law_no]['name']
                            );
                            $relate_records = array();
                            foreach ($relate_law->numbers as $number) {
                                if (array_key_exists($number, $laws[$relate_law->law_no]['rule_note'])) {
                                    $anchor = $laws[$relate_law->law_no]['rule_note'][$number];
                                } else {
                                    $anchor = $number;
                                }
                                $relate_records[] = "[{$number}]({$relate_url}#{$anchor})";
                            }
                            $ret->content .= "> {$type}: {$relate_law->law_name} " . implode(' ', $relate_records) . "\n\n";
                        }
                    }
                }
            }

            $ret->content .= "\n";
            $ret->content .= "\n";
        }
        $ret->content = trim($ret->content);;
        unset($obj->law_data);

        if (property_exists($obj, 'law_reasons') and !count($obj->law_reasons)) {
            unset($obj->law_reasons);
        }
            unset($obj->law_reasons); // XXX: 姓名條例對不到

        if (!preg_match('#中華民國(.*)年(.*)月(.*)日(.*)#', $obj->versions[count($obj->versions) - 1], $matches)) {
            throw new Error2("找不到最後時間");
        }
        $ret->commit_at = sprintf("%d/%d/%d", $matches[1] + 1911, $matches[2], $matches[3]);
        $ret->commit_log = $obj->title . ' ' . implode(',', array_map(function($a) {
            preg_match('#中華民國(.*)年(.*)月(.*)日(.*)#', $a, $matches);
            return sprintf("%d/%d/%d %s", $matches[1] + 1911, $matches[2], $matches[3], $matches[4]);
        }, $obj->versions));

        unset($obj->versions);

        if (property_exists($obj, 'law_history')) {
            $ret->commit_authors = array();
            foreach ($obj->law_history as $history) {
                if (property_exists($history, '主提案')) {
                    $ret->commit_authors[] = $history->{'主提案'};
                }
            }
            $ret->commit_log .= "\n\n" . json_encode($obj->law_history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
            unset($obj->law_history);
        }

        if (property_exists($obj, 'deprecated_reason')) {
            $ret->commit_log .= "\n\n" . '廢除原因:' . $obj->deprecated_reason;
            unset($obj->deprecated_reason);
        }

        unset($obj->title);

        if (json_encode($obj) != '{}') {
            echo 'ret=';
            var_dump($ret);
            echo 'obj=';
            var_dump($obj);
            throw new Error2("還有 obj 未處理");
        }
        return $ret;
    }

    public function parseDeprecatedHTML($content)
    {
        $doc = new DOMDocument;
        @$doc->loadHTML($content);

        foreach ($doc->getElementsByTagName('td') as $td_dom) {
            if ($td_dom->getAttribute('class') == 'artipud_RS_2') {
                return trim($td_dom->nodeValue);
            }
        }
        return '';
        throw new Error2("找不到 td.artipud_RS_2");
    }

    public function parseRelateHTML($content)
    {
        $doc = new DOMDocument;
        @$doc->loadHTML($content);

        $ret = array();
        $table_dom = $doc->getElementsByTagName('font')->item(0)->parentNode->nextSibling;
        while ($table_dom = $table_dom->nextSibling) {
            $group = null;
            $law = null;
            foreach ($table_dom->getElementsByTagName('font') as $font_dom) {
                if ($font_dom->getAttribute('color') == 'blue') {
                    $text = trim($font_dom->nodeValue);
                    $text = trim($text, ':');
                    if (in_array($text, array('引用條文', '被引用條文'))) {
                        $group = $text;
                        $law = null;
                        if (array_key_exists($group, $ret)) {
                            throw new Exception("$group 出現兩次");
                        }
                        $ret[$group] = array();
                    } elseif (preg_match('#^(.*)\((.*)\)$#', $text, $matches)) {
                        if (is_null($group)) {
                            print_r($ret);
                            echo $doc->saveHTML($table_dom);
                            throw new Exception("錯誤");
                        }
                        $law_name = $matches[1];
                        $law = $matches[2];
                        $ret[$group][$law] = array(
                            'law_no' => $law,
                            'law_name' => $law_name,
                            'numbers' => array(),
                        );
                    } else {
                        echo $doc->saveHTML($font_dom);
                        throw new Exception("不明格式");
                    }
                } elseif ($font_dom->getAttribute('color') == 'c000ff') {
                    if (is_null($group) or is_null($law)) {
                        echo $doc->saveHTML($font_dom);
                        throw new Exception("不明格式");
                    }
                    $ret[$group][$law]['numbers'][] = trim($font_dom->nodeValue);
                } else {
                    echo $doc->saveHTML($font_dom);
                    throw new Exception("不明格式");
                }
            }
        }
        return array_map('array_values', $ret);
    }

    public function parseLawHTML($content)
    {
        $doc = new DOMDocument;
        @$doc->loadHTML($content);
        $table_dom = $doc->getElementById('C_box')->getElementsByTagName('table')->item(1);

        $lines = array();

        foreach ($table_dom->childNodes as $tr_dom) {
            $td_doms = $tr_dom->childNodes;

            // 正常來說 <tr> 裡面只會有一個 <td>
            if ($td_doms->length != 1) {
                echo $doc->saveHtml($tr_dom);
                throw new Error2("td 應該只有一個");
            }
            $td_dom = $td_doms->item(0);
            $name = null;

            $line = new StdClass;
            while (true) {
                $pos = 0;
                while ($n = $td_dom->childNodes->item($pos) and $n->nodeName == '#text' and trim($n->nodeValue) == '') {
                    $pos ++;
                }

                // 如果 <td> 內純文字開頭，表示進入法案內容，就可以跳出了
                if ($pos >= $td_dom->childNodes->length or $td_dom->childNodes->item($pos)->nodeName == '#text') {
                    $line->rule_no = trim($name);
                    $line->content = $this->trim($td_dom->nodeValue);
                    $line->relates = new StdClass;
                    foreach ($td_dom->getElementsByTagName('img') as $img_dom) {
                        if ($img_dom->getAttribute('src') != "/lglaw/images/relate.png") {
                            continue;
                        }
                        $relate_id = explode('?', $img_dom->parentNode->getAttribute('href'))[1];
                        if (json_encode($line->relates) != '{}') {
                            print_r($line);
                            exit;
                        }
                        $line->relates = self::parseRelateHTML(file_get_contents(__DIR__ . '/laws/relate/' . $relate_id . '.html'));
                    }
                    $lines[] = $line;
                    break;
                }

                // 第一個 node 一定是 <font> 並且裡面會有章節條名稱
                $cnode = $td_dom->childNodes->item($pos);
                if ($cnode->nodeName == 'font') {
                    $pos ++;
                    if (!is_null($name)) {
                        $line->section_name = $name;
                        $lines[] = $line;
                        $line = new StdClass;
                    }
                    $name = $cnode->nodeValue;

                    // 下一個 node 如果是純文字，就是備註，要不然就是法條內容
                    $cnode = $td_dom->childNodes->item($pos);
                    if ($cnode->nodeName == '#text') {
                        $line->note = $this->trim($cnode->nodeValue);
                        $pos ++;
                    }
                }

                // 下一個一定是 <table>
                $cnode = $td_dom->childNodes->item($pos);
                if ($cnode->nodeName != 'table') {
                    echo $doc->saveHtml($tr_dom);
                    throw new Error2("tr 下應該要有 <font/> 和 <table />");
                }

                $td_dom = $cnode->getElementsByTagName('td')->item(1);

            }
        }
        return $lines;
    }

    public function parseHistoryHTML($content)
    {
        $doc = new DOMDocument;
        @$doc->loadHTML($content);
        $records = array();
        foreach ($doc->getElementsByTagName('table') as $table_dom) {
            if ($table_dom->getAttribute('class') == 'sumtab04') {
                foreach ($table_dom->getElementsByTagName('div') as $div_dom) {
                    if ($div_dom->getAttribute('id') == 'all') {
                        continue;
                    }
                    foreach ($div_dom->getElementsByTagName('tr') as $tr_dom) {
                        $td_doms = $tr_dom->getElementsByTagName('td');
                        if (!$td_doms->item(0)) {
                            continue;
                        }
                        if ($td_doms->item(0)->getAttribute('class') != 'sumtd1') {
                            continue;
                        }
                        $record = new StdClass;
                        $record->{'進度'} = $td_doms->item(0)->nodeValue;
                        $record->{'會議日期'} = $td_doms->item(1)->nodeValue;
                        if (!$td_doms->item(2)) {
                            throw new Error2("找不到立法紀錄的表格");
                        }
                        $record->{'立法紀錄'} = $td_doms->item(2)->nodeValue;
                        if ($td_doms->item(2)->getElementsByTagName('a')->item(0)) {
                            $record->{'立法紀錄連結'} = $td_doms->item(2)->getElementsByTagName('a')->item(0)->getAttribute('href');
                        }
                        if ($p = $this->trim($td_doms->item(3)->nodeValue)) {
                            $record->{'主提案'} = $p;
                        }
                        $record->{'關係文書'} = array();
                        if (!$td_doms->item(4)) {
                            $a_doms = array();
                        } else {
                            $a_doms = $td_doms->item(4)->getElementsByTagName('a');
                        }
                        foreach ($a_doms as $a_dom) {
                            $href = $a_dom->getAttribute('href');
                            if (strpos($href, '/') === 0) {
                                $href = 'http://lis.ly.gov.tw' . $href;
                            }
                            if (!$text_dom = $a_dom->nextSibling or $text_dom->nodeType != XML_TEXT_NODE) {
                                $record->{'關係文書'}[] = array(
                                    'null',
                                    $href,
                                );
                                continue;
                            }
                            $text = trim($text_dom->nodeValue, html_entity_decode('&nbsp;') . "\n\r");
                            $text = preg_replace('#\s#', '', $text);
                            if (!preg_match('#^\((.*)\),?$#', $text, $matches)) {
                                $text = '';
                            } else {
                                $text = $matches[1];
                            }
                            $record->{'關係文書'}[] = array(
                                $text,
                                $href,
                            );
                        }
                        $records[] = $record;
                    }
                }
            }

        }
        return $records;
    }

    public function parseReasonHTML($content)
    {
        $doc = new DOMDocument;
        @$doc->loadHTML($content);
        $reasons = new StdClass;
        foreach ($doc->getElementsByTagName('td') as $td_dom) {
            if ($td_dom->getAttribute('class') != 'artipud_RS_2') {
                continue;
            }
            $reason = $td_dom->nodeValue;
            $tr_dom = $td_dom->parentNode;
            while ($tr_dom = $tr_dom->previousSibling) {
                if ($tr_dom->nodeType !== XML_ELEMENT_NODE) {
                    continue;
                }
                $font_dom = $tr_dom->getElementsByTagName('font')->item(0);
                if (!$font_dom) {
                    continue;
                }
                if ($font_dom->getAttribute('color') == '#8600B3') {
                    break;
                }
            }
            if (!$font_dom) {
                throw new Error2("找不到 {$reason} 對應到的 .artiupd_TH_1 是哪一條");
            }
            $reasons->{trim($font_dom->nodeValue)} = $reason;
        }
        return $reasons;
    }

    public function main()
    {
        if (!file_exists(__DIR__ . '/law_cache')) {
            mkdir(__DIR__ . '/law_cache');

            mkdir(__DIR__ . '/outputs_markdown');
            system("git -C ./outputs_markdown init");
            system("git -C ./outputs_markdown checkout -b markdown");
            system("git -C ./outputs_markdown remote add origin " . REPOSITORY_URI);

            mkdir(__DIR__ . '/outputs_json');
            system("git -C ./outputs_json init");
            system("git -C ./outputs_json checkout -b json");
            system("git -C ./outputs_json remote add origin " . REPOSITORY_URI);
        }

        $laws = array();
        $fp = fopen('catalogue/laws.csv', 'r');
        fgetcsv($fp);
        while ($rows = fgetcsv($fp)) {
            list($id, $name, $type) = $rows;
            $laws[$id] = array(
                'id' => $id,
                'name' => $name,
                'type' => $type,
                'categories' => array(),
                'rule_note' => array(),
            );
        }

        fclose($fp);

        $has_images = array();
        $fp = fopen('gravatar/save-images.csv', 'r');
        fgetcsv($fp);
        while ($rows = fgetcsv($fp)) {
            $has_images[$rows[0]] = true;
        }
        fclose($fp);

        $fp = fopen('catalogue/laws-category.csv', 'r');
        fgetcsv($fp);
        while ($rows = fgetcsv($fp)) {
            list($category1, $category2, $type, $id, $name)= $rows;
            $laws[$id]['categories'][] = array(
                $category1, $category2
            );
        }
        fclose($fp);

        error_log("parsing");
        $fp = fopen('catalogue/laws-versions.csv', 'r');
        fgetcsv($fp);
        $commits = array();

        while ($rows = fgetcsv($fp)) {
            list($id, $title, $versions, $types) = $rows;

            if (count($_SERVER['argv']) > 1) {
                if ($title != $_SERVER['argv'][1]) {
                    continue;
                }
            }

            $versions = explode(';', $versions);
            if (strlen($types)) {
                $types = explode(';', $types);
            } else {
                $types = array();
            }

            // 先抓本文
            $content = file_get_contents("laws/{$id}/{$versions[0]}.html");
            $obj = new StdClass;
            $obj->title = $title;
            $obj->versions = $versions;
            $obj->types = $types;
            try {
                $obj->law_data = $this->parseLawHTML($content);
            } catch (Error2 $e) {
                $e->setMessage("{$title} {$versions[0]} {$e->getMessage()}");
                throw $e;
            }

            foreach ($obj->law_data as $record) {
                if (property_exists($record, 'rule_no')) {
                    if (property_exists($record, 'note')) {
                        $laws[$id]['rule_note'][$record->rule_no] = $record->rule_no . '-' . preg_replace('#[\()]#', '', $record->note);
                    } else  {
                        $laws[$id]['rule_note'][$record->rule_no] = $record->rule_no;
                    }
                }
            }

            foreach ($types as $type) {
                try {
                    if ($type == '異動條文') {
                        // do nothing, 因為異動條文直接 diff 就可以得到了
                    } elseif ($type == '立法歷程') {
                        $content = file_get_contents("laws/{$id}/{$versions[0]}-立法歷程.html");
                        $obj->law_history = $this->parseHistoryHTML($content);
                    } elseif ($type == '異動條文及理由') {
                        $content = file_get_contents("laws/{$id}/{$versions[0]}-異動條文及理由.html");
                        $obj->law_reasons = $this->parseReasonHTML($content);
                    } elseif ($type == '廢止理由') {
                        $content = file_get_contents("laws/{$id}/{$versions[0]}-{$type}.html");
                        $obj->deprecated_reason = $this->parseDeprecatedHTML($content);
                    } else {
                        throw new Exception("TODO {$type} 未處理");
                    }
                } catch (Error2 $e) {
                    $e->setMessage("{$title} {$versions[0]} {$type} {$e->getMessage()}");
                    throw $e;
                } catch (Exception $e) {
                    throw new Exception("{$title} {$versions[0]} {$type} {$e->getMessage()}");
                }
            }
            if (!preg_match('#中華民國(.*)年(.*)月(.*)日#', $obj->versions[count($obj->versions) - 1], $matches)) {
                throw new Error2("找不到最後時間");
            }
            $commit_at = sprintf("%04d%02d%02d", $matches[1] + 1911, $matches[2], $matches[3]);
            file_put_contents("law_cache/{$id}.json", json_encode($obj));
            $commits[] = array($commit_at, $id, $title, $versions);
        }
        fclose($fp);

        usort($commits, function($a, $b) { return $a[0] - $b[0]; });

        error_log('writing');
        foreach ($commits as $time_obj) {
            list($commit_at, $id, $title, $versions) = $time_obj;
            error_log($id);
            if (!$obj = json_decode(file_get_contents("law_cache/{$id}.json"))) {
                continue;
            }

            try {
                $info = self::genContent($obj, $laws);
            } catch (Error2 $e) {
                $e->setMessage("{$title} {$versions[0]} {$e->getMessage()}");
                throw $e;
            }
            file_put_contents(__DIR__ . "/outputs_markdown/{$id}.md", $info->content);
            file_put_contents(__DIR__ . "/outputs_json/{$id}.json", json_encode($obj, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            system("git -C ./outputs_markdown add .");
            system("git -C ./outputs_json add .");

            unset($info->content);

            $terms = array();
            if ($info->commit_at) {
                $terms[] = "--date=" . escapeshellarg(date('c', strtotime($info->commit_at)));
                unset($info->commit_at);
            }

            if (property_exists($info, 'commit_authors') and $info->commit_authors) {
                $commit_author = array_shift($info->commit_authors);
                if (array_key_exists($commit_author, $has_images)) {
                    $mail = substr(md5($commit_author), 0, 16) . "@ly.govapi.tw";
                } else {
                    $mail = $commit_author . "@ly.govapi.tw";
                }
                $terms[] = "--author=" . escapeshellarg("{$commit_author} <{$mail}>");

                foreach ($info->commit_authors as $commit_author) {
                    if (array_key_exists($commit_author, $has_images)) {
                        $mail = substr(md5($commit_author), 0, 16) . "@ly.govapi.tw";
                    } else {
                        $mail = $commit_author . "@ly.govapi.tw";
                    }
                    $info->commit_log .= "\nCo-Authored-By: {$commit_author} <{$mail}>";
                }
            }
            unset($info->commit_authors);

            $terms[] = "-m " . escapeshellarg($info->commit_log);
            unset($info->commit_log);

            if (json_encode($info) != '{}') {
                print_r($info);
                throw new Exception("還有 info 欄位未處理");
            }
            $cmd = ("git -C ./outputs_markdown commit " . implode(' ', $terms));
            error_log($cmd);
            system($cmd);
            $cmd = ("git -C ./outputs_json commit " . implode(' ', $terms));
            error_log($cmd);
            system($cmd);

        }
    }
}

$e = new Exporter;
$e->main();
