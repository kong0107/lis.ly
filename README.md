lis.ly
======

將 [立法院法律系統](http://lis.ly.gov.tw/lglawc/lglawkm) 的法律修改歷程資料轉換成 markdown 與 JSON ，並以 git repository 方式儲存。
分叉自 [ronnywang/twlaw](https://github.com/ronnywang/twlaw) 。

環境
----
PHP with `cURL`, `XML`, and `mbstring` support

`sudo apt-get install php-curl php-xml php-mbstring`

使用方法
--------
* 先執行 php crawl.php 會將法條列表爬下來，列表存在 laws.csv ，各法條 HTML 會存在 `laws/` 。
* 手動修改 `export-to-git.php` 中的 `$repository`
* 再執行 `php export-to-git.php` 會將前面抓下來的 HTML ，在 `outputs_markdown/` 產生 markdown 格式的修改歷程，在 `outputs_json/` 產生 json 格式的修改歷程。
* 在 `outputs_<format>/` 下執行 `git push -f origin <format>[:<branch>]` ，可強制把該對應格式的內容推到 git 伺服器的 branch 分支

輸出成果
--------
* [catalogue](https://github.com/kong0107/lis.ly/tree/catalogue)
* [markdown](https://github.com/kong0107/lis.ly/tree/markdown)
* [json](https://github.com/kong0107/lis.ly/tree/json)

備註
----
* 「法條分類」分為「主分類」(Ex: 內政、外交僑務...)，以及「次分類」(Ex: 內政>民政、外交僑務>領務...)，
  一個法條有可能同時存在於兩個分類(Ex: 財團法人原住民族文化事業基金會設置條例 同時存在於 內政>人民團體 和 原住民族>原民文教)
* 法條名稱也不是唯一，例如 交通建設>交通政務>交通部組織法 有已廢止(ID=02001)和現行法(ID=02080) 兩個版本

License
-------
程式碼以 BSD License 開放授權

Changelog
---------
* 將 repository 的位址改寫於 `config.php` ，方便改寫。
* 把 `laws*.csv` 換放到 `catalogue/` ，以利分離程式與資料。但也因此多了一個資料夾。
* 把 `outputs` 改為 `outputs_markdown` 。
* 把存檔路徑從 `{主分類}/{次分類}/{法律名稱}.md` 換成 `{id}.md` （JSON版亦仿之）。
* `export-to-git.php` 的 `class Error` 更名為 `Error2` ，以避免與 [PHP 7 的內建類別](https://php.net/manual/class.error.php)名字相衝。
