<?php
defined('WEKIT_VERSION') or exit(403);

class PwThreadsBatchDeleteDoTorrent
{
    
    /**
     * @param array $ids 帖子tid序列
     * @return void
     */
    public function appDo($ids) {
        $torrentDao = Wekit::load('EXT:torrent.service.dao.PwTorrentDao');
        $fileDao = Wekit::load('EXT:torrent.service.dao.PwTorrentFileDao');
        foreach ($ids as $id) {
            $torrent = $torrentDao->fetchTorrentByTid($id);
            $files = $fileDao->getTorrentFileByTorrent($torrent['id']);
            foreach ($files as $file) $fileDao->deleteTorrentFile($file['id']);
            $torrentDa->deleteTorrent($torrent['id']);
            @unlink('../../../../../torrent/' . $torrent['id'] . '.torrent');
        }
    }
}
?>