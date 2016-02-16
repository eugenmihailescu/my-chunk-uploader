<?php
/**
 * ################################################################################
 * MyChunkUploader
 * 
 * Copyright 2016 Eugen Mihailescu <eugenmihailescux@gmail.com>
 * 
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation, either version 3 of the License, or (at your option) any later
 * version.
 * 
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
 * PARTICULAR PURPOSE.  See the GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License along with
 * this program.  If not, see <http://www.gnu.org/licenses/>.
 * 
 * ################################################################################
 * 
 * Short description:
 * URL: https://github.com/eugenmihailescu/my-chunk-uploader
 * 
 * Git revision information:
 * 
 * @version : 0.2.3-3 $
 * @commit  : 961115f51b7b32dcbd4a8853000e4f8cc9216bdf $
 * @author  : Eugen Mihailescu <eugenmihailescux@gmail.com> $
 * @date    : Tue Feb 16 15:27:30 2016 +0100 $
 * @file    : upload.php $
 * 
 * @id      : upload.php | Tue Feb 16 15:27:30 2016 +0100 | Eugen Mihailescu <eugenmihailescux@gmail.com> $
*/

namespace MyChunkUploader;

require_once 'MyChunkUploader.php';
$uploader = new MyChunkUploader();
$uploader->run();
?>