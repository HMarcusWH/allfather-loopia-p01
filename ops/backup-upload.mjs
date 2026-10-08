import fs from "node:fs";
import crypto from "node:crypto";
import { S3Client, PutObjectCommand, HeadObjectCommand, GetObjectCommand } from "@aws-sdk/client-s3";
const e=process.env;
for(const k of ["S3_ENDPOINT","S3_REGION","S3_BUCKET","S3_ACCESS_KEY_ID","S3_SECRET_ACCESS_KEY","SNAPSHOT_FILE","SNAPSHOT_KEY"]){if(!e[k])throw Error("missing "+k)}
if(!e.SNAPSHOT_KEY.startsWith("staging/manual-")||!e.SNAPSHOT_KEY.endsWith(".tar.age"))throw Error("unsafe destination");
const s3=new S3Client({endpoint:e.S3_ENDPOINT,region:e.S3_REGION,forcePathStyle:false,credentials:{accessKeyId:e.S3_ACCESS_KEY_ID,secretAccessKey:e.S3_SECRET_ACCESS_KEY}});
const bytes=fs.readFileSync(e.SNAPSHOT_FILE);
const digest=crypto.createHash("sha256").update(bytes).digest("hex");
const obj={Bucket:e.S3_BUCKET,Key:e.SNAPSHOT_KEY};
await s3.send(new PutObjectCommand({...obj,Body:bytes,ContentType:"application/octet-stream",Metadata:{"ciphertext-sha256":digest,"encryption":"age-x25519","scope":"railway-staging-db-only"}}));
const head=await s3.send(new HeadObjectCommand(obj));
if(Number(head.ContentLength)!==bytes.length)throw Error("retained length mismatch");
const download=await s3.send(new GetObjectCommand(obj));
const actual=Buffer.from(await download.Body.transformToByteArray());
const remoteSha=crypto.createHash("sha256").update(actual).digest("hex");
if(remoteSha!==digest)throw Error("retained object checksum mismatch");
console.log("RETAINED_ENCRYPTED_OBJECT_VERIFIED",JSON.stringify({objectKey:e.SNAPSHOT_KEY,ciphertextSha256:digest,encryptedBytes:bytes.length,publicDecryptKeyRequired:true,region:e.S3_REGION,verifiedByReadback:true}));
