<?php

defined('WEKIT_VERSION') || exit('Forbidden');

Wind::import('SRV:forum.srv.post.do.PwPostDoBase');
Wind::import('EXT:torrent.service.srv.helper.PwBencode');
Wind::import('EXT:torrent.service.srv.helper.PwUtils');

class PwPostDoTorrent extends PwPostDoBase
{
    protected $user;
    protected $tid;
    protected $fid;
    protected $wikilink;
    protected $dictionary;
    protected $infohash;
    protected $filename;
    protected $filesavename;
    protected $filelist;
    protected $totalength;
    protected $action;

    public function __construct(PwPost $pwpost, $tid = null, $wikilink = '')
    {
        $this->user     = $pwpost->user;
        $this->special  = $pwpost->special;
        $this->tid      = $tid ? intval($tid) : null;
        $this->fid      = intval($pwpost->forum->fid);
        $this->wikilink = $wikilink;
        $this->action   = $this->tid ? 'modify' : 'add';
        $this->passkey  = PwUtils::getPassKey($this->user->uid);
    }

    public function createHtmlBeforeContent()
    {
        PwHook::template('displayPostTorrentHtml', 'EXT:torrent.template.post_injector_before_torrent', true, $this);
    }

    public function dataProcessing($postDm)
    {
        $postDm->setSpecial('torrent');
        return $postDm;
    }

    public function addThread($tid)
    {
        return $this->addTorrentt($tid);
    }

    public function check($postDm)
    {
        $bencode = new PwBencode();
        if (!is_array($deniedfts)) {
            $deniedfts = array();
        }

        if (isset($_FILES['torrent'])) {
            $file = pathinfo($_FILES['torrent']['name']);
            if ($file['extension'] != 'torrent') {
                return new PwError('只允许上传后缀名为.torrent的文件！');
            }
            if ($_FILES['torrent']['size'] < 1) {
                return new PwError('上传文件大小有问题，为空！');
            }
            $filename   = $_FILES['torrent']['name'];
            $dictionary = $bencode->doDecodeFile($_FILES['torrent']['tmp_name']);
            if (!isset($dictionary)) {
                return new PwError('种子读取错误，请检查种子是否正确！');
            }
            list($announce, $info)                       = $bencode->doDictionaryCheck($dictionary, 'announce(string):info');
            list($dictionaryName, $pieceLength, $pieces) = $bencode->doDictionaryCheck($info, 'name(string):piece length(integer):pieces(string)');
            if (strlen($pieces) % 20 != 0) {
                return new PwError('无效的文件块，请检查种子是否正确！');
            }
            $fileList    = array();
            $totalLength = $bencode->doDictionaryGet($info, 'length', 'integer');
            if (isset($totalLength)) {
                $fileList[] = array($dictionaryName, $totalLength);
            } else {
                $flist = $bencode->doDictionaryGet($info, 'files', 'list');
                if (!isset($flist)) {
                    return new PwError('种子缺少长度和文件，请检查种子是否正确！');
                }
                if (!count($flist)) {
                    return new PwError('种子不存在任何文件，请检查种子是否正确！');
                }

                $totalLength = 0;

                if (is_array($flist)) {
                    foreach ($flist as $fn) {
                        list($ll, $ff) = $bencode->doDictionaryCheck($fn, 'length(integer):path(list)');

                        $totalLength += $ll;

                        $ffa = array();

                        if (is_array($ff)) {
                            foreach ($ff as $ffe) {
                                if ($ffe['type'] != 'string') {
                                    return new PwError('种子存在文件名错误，请检查种子是否正确！');
                                }
                                $ffa[] = $ffe['value'];
                            }
                        }

                        if (!count($ffa)) {
                            return new PwError('种子存在文件名错误，请检查种子是否正确！');
                        }

                        $ffe        = implode('/', $ffa);
                        $fileList[] = array($ffe, $ll);
                    }
                }
            }

            $torrentcheck = Wekit::C('site', 'app.torrent.check');

            if (is_array($torrentcheck)) {
                if (in_array('deniedfts', $torrentcheck)) {
                    $deniedfts = Wekit::C('site', 'app.torrent.deniedfts');

                    if (is_array($deniedfts)) {
                        foreach ($fileList as $file) {
                            $ft = substr(strrchr($file[0], '.'), 1);
                            if (in_array($ft, $deniedfts)) {
                                return new PwError('种子内存在禁止发布的文件类型: ' . $ft);
                            }
                        }
                    }
                }

                if (in_array('source', $torrentcheck)) {
                    $dictionary['value']['info']['value']['source'] = $bencode->doDecode($bencode->doEncodeString(Wekit::C('site', 'info.name')));
                }
            }

            $dictionary['value']['announce']                 = $bencode->doDecode($bencode->doEncodeString(Wekit::C('site', 'info.url') . '/announce.php'));
            $dictionary['value']['info']['value']['private'] = $bencode->doDecode('i1e');

            unset($dictionary['value']['announce-list']);
            unset($dictionary['value']['nodes']);

            $dictionary = $bencode->doDecode($bencode->doEncode($dictionary));

            list($announce, $info) = $bencode->doDictionaryCheck($dictionary, 'announce(string):info');

            $infohash = pack('H*', sha1($info['string']));

            $check = $this->_getTorrentService()->getTorrentByInfoHash($infohash);

            if ($check) {
                return new PwError('不能发布重复种子资源');
            }

            $this->dictionary   = $dictionary;
            $this->infohash     = $infohash;
            $this->filename     = $filename;
            $this->filesavename = $dictionaryName;
            $this->filelist     = $fileList;
            $this->totalength   = $totalLength;

            return true;
        } else {
            return new PwError('必须上传一个种子文件！');
        }
    }

    public function addTorrentt($tid)
    {
        $dm = Wekit::load('EXT:torrent.service.dm.PwTorrentDm');
        $dm->setTid($tid)->setInfoHash($this->infohash)->setOwner($this->user->uid)->setAnonymous(0)->setSize($this->totalength)->setWikilink($this->wikilink)->setFileName($this->filename)->setSaveAs($this->filesavename)->setCreatedAt(date('Y-m-d H:i:s'))->setUpdatedAt(date('Y-m-d H:i:s'));

        $result = $this->_getTorrentService()->addTorrent($dm);

        if ($result instanceof PwError) {
            return $result;
        }

        if (is_array($this->filelist)) {
            $filedm = Wekit::load('EXT:torrent.service.dm.PwTorrentFileDm');

            foreach ($this->filelist as $file) {
                $filedm->setTorrentId($result);
                $filedm->setFileName($file[0]);
                $filedm->setSize($file[1]);
                $this->_getTorrentFileService()->addTorrentFile($filedm);
            }
        }

        if (!is_dir(WEKIT_PATH . '../torrents')) {
            mkdir(WEKIT_PATH . '../torrents', 0755);
        }

        $fp = fopen(WEKIT_PATH . '../torrents/' . $result . '.torrent', 'w');

        if ($fp) {
            $bencode = new PwBencode();
            @fwrite($fp, $bencode->doEncode($this->dictionary));
            fclose($fp);
        }

        return true;
    }

    private function _getTorrentService()
    {
        return Wekit::load('EXT:torrent.service.PwTorrent');
    }

    private function _getTorrentFileService()
    {
        return Wekit::load('EXT:torrent.service.PwTorrentFile');
    }
}
