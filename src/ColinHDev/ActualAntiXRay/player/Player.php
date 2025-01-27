<?php

namespace ColinHDev\ActualAntiXRay\player;

use ColinHDev\ActualAntiXRay\ResourceManager;
use ColinHDev\ActualAntiXRay\tasks\ChunkRequestTask;
use pocketmine\block\tile\Spawnable;
use pocketmine\event\player\PlayerPostChunkSendEvent;
use pocketmine\network\mcpe\cache\ChunkCache;
use pocketmine\network\mcpe\compression\CompressBatchPromise;
use pocketmine\network\mcpe\compression\Compressor;
use pocketmine\network\mcpe\protocol\BlockActorDataPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\player\Player as PMMP_PLAYER;
use pocketmine\player\UsedChunkStatus;
use pocketmine\timings\Timings;
use pocketmine\utils\Utils;
use pocketmine\world\World;
use ReflectionProperty;
use function var_dump;

class Player extends PMMP_PLAYER {

    /**
     * @var true[]
     * @phpstan-var array<int, true>
     */
    private array $activeChunkGenerationRequests = [];

    /**
     * Requests chunks from the world to be sent, up to a set limit every tick. This operates on the results of the most recent chunk
     * order.
     */
    protected function requestChunks() : void{
        if(!$this->isConnected()){
            return;
        }

        if (!ResourceManager::getInstance()->isEnabledForWorld($this->getWorld()->getFolderName())) {
            parent::requestChunks();
            return;
        }

        Timings::$playerChunkSend->startTiming();

        $count = 0;
        $world = $this->getWorld();

        $limit = $this->chunksPerTick - count($this->activeChunkGenerationRequests);
        foreach($this->loadQueue as $index => $distance){
            if($count >= $limit){
                break;
            }

            $X = null;
            $Z = null;
            World::getXZ($index, $X, $Z);
            assert(is_int($X) && is_int($Z));

            ++$count;

            $this->usedChunks[$index] = UsedChunkStatus::REQUESTED_GENERATION();
            $this->activeChunkGenerationRequests[$index] = true;
            unset($this->loadQueue[$index]);
            $this->getWorld()->registerChunkLoader($this->chunkLoader, $X, $Z, true);
            $this->getWorld()->registerChunkListener($this, $X, $Z);

            $this->getWorld()->requestChunkPopulation($X, $Z, $this->chunkLoader)->onCompletion(
                function() use ($X, $Z, $index, $world) : void{
                    if(!$this->isConnected() || !isset($this->usedChunks[$index]) || $world !== $this->getWorld()){
                        return;
                    }
                    if(!$this->usedChunks[$index]->equals(UsedChunkStatus::REQUESTED_GENERATION())){
                        //We may have previously requested this, decided we didn't want it, and then decided we did want
                        //it again, all before the generation request got executed. In that case, the promise would have
                        //multiple callbacks for this player. In that case, only the first one matters.
                        return;
                    }
                    unset($this->activeChunkGenerationRequests[$index]);
                    $this->usedChunks[$index] = UsedChunkStatus::REQUESTED_SENDING();

                    $this->startUsingChunk($X, $Z, function() use ($X, $Z, $index) : void{
                        $this->usedChunks[$index] = UsedChunkStatus::SENT();
                        if($this->spawnChunkLoadCount === -1){
                            $this->spawnEntitiesOnChunk($X, $Z);
                        }elseif($this->spawnChunkLoadCount++ === $this->spawnThreshold){
                            $this->spawnChunkLoadCount = -1;

                            $this->spawnEntitiesOnAllChunks();

                            $this->getNetworkSession()->notifyTerrainReady();
                        }
                        (new PlayerPostChunkSendEvent($this, $X, $Z))->call();
                    });
                },
                static function() : void{
                    //NOOP: we'll re-request this if it fails anyway
                }
            );
        }

        Timings::$playerChunkSend->stopTiming();
    }

    /**
     * Instructs the networksession to start using the chunk at the given coordinates. This may occur asynchronously.
     * @param \Closure $onCompletion To be called when chunk sending has completed.
     * @phpstan-param \Closure() : void $onCompletion
     */
    public function startUsingChunk(int $chunkX, int $chunkZ, \Closure $onCompletion) : void{
        Utils::validateCallableSignature(function() : void{}, $onCompletion);

        $world = $this->getLocation()->getWorld();
        $this->request(ChunkCache::getInstance($world, $this->getNetworkSession()->getCompressor()), $chunkX, $chunkZ)->onResolve(

        //this callback may be called synchronously or asynchronously, depending on whether the promise is resolved yet
            function(CompressBatchPromise $promise) use ($world, $onCompletion, $chunkX, $chunkZ) : void{
                if(!$this->isConnected()){
                    return;
                }
                $currentWorld = $this->getLocation()->getWorld();
                if($world !== $currentWorld || ($status = $this->getUsedChunkStatus($chunkX, $chunkZ)) === null){
                    $this->logger->debug("Tried to send no-longer-active chunk $chunkX $chunkZ in world " . $world->getFolderName());
                    return;
                }
                if(!$status->equals(UsedChunkStatus::REQUESTED_SENDING())){
                    //TODO: make this an error
                    //this could be triggered due to the shitty way that chunk resends are handled
                    //right now - not because of the spammy re-requesting, but because the chunk status reverts
                    //to NEEDED if they want to be resent.
                    return;
                }
                $world->timings->syncChunkSend->startTiming();
                try{
                    $this->getNetworkSession()->queueCompressed($promise);
                    $onCompletion();

                    //TODO: HACK! we send the full tile data here, due to a bug in 1.19.10 which causes items in tiles
                    //(item frames, lecterns) to not load properly when they are sent in a chunk via the classic chunk
                    //sending mechanism. We workaround this bug by sending only bare essential data in LevelChunkPacket
                    //(enough to create the tiles, since BlockActorDataPacket can't create tiles by itself) and then
                    //send the actual tile properties here.
                    //TODO: maybe we can stuff these packets inside the cached batch alongside LevelChunkPacket?
                    $chunk = $currentWorld->getChunk($chunkX, $chunkZ);
                    if($chunk !== null){
                        foreach($chunk->getTiles() as $tile){
                            if(!($tile instanceof Spawnable)){
                                continue;
                            }
                            $this->getNetworkSession()->sendDataPacket(BlockActorDataPacket::create(BlockPosition::fromVector3($tile->getPosition()), $tile->getSerializedSpawnCompound()));
                        }
                    }
                }finally{
                    $world->timings->syncChunkSend->stopTiming();
                }
            }
        );
    }

    /**
     * Requests asynchronous preparation of the chunk at the given coordinates.
     *
     * @return CompressBatchPromise a promise of resolution which will contain a compressed chunk packet.
     */
    public function request(ChunkCache $chunkCache, int $chunkX, int $chunkZ) : CompressBatchPromise{
        $property = new ReflectionProperty(ChunkCache::class, "world");
        $property->setAccessible(true);
        /** @var World $world */
        $world = $property->getValue($chunkCache);

        $world->registerChunkListener($chunkCache, $chunkX, $chunkZ);
        $chunk = $world->getChunk($chunkX, $chunkZ);
        if($chunk === null){
            throw new \InvalidArgumentException("Cannot request an unloaded chunk");
        }
        $chunkHash = World::chunkHash($chunkX, $chunkZ);

        $cacheProperty = new ReflectionProperty(ChunkCache::class, "caches");
        $cacheProperty->setAccessible(true);
        /** @var CompressBatchPromise[] $caches */
        $caches = $cacheProperty->getValue($chunkCache);

        if(isset($caches[$chunkHash])){

            $property = new ReflectionProperty(ChunkCache::class, "hits");
            $property->setAccessible(true);
            /** @var int $hits */
            $hits = $property->getValue($chunkCache);
            $property->setValue($chunkCache, $hits + 1);

            return $caches[$chunkHash];
        }

        $property = new ReflectionProperty(ChunkCache::class, "misses");
        $property->setAccessible(true);
        /** @var int $misses */
        $misses = $property->getValue($chunkCache);
        $property->setValue($chunkCache, $misses + 1);

        $world->timings->syncChunkSendPrepare->startTiming();
        try{
            $caches[$chunkHash] = new CompressBatchPromise();
            $cacheProperty->setValue($chunkCache, $caches);

            $property = new ReflectionProperty(ChunkCache::class, "compressor");
            $property->setAccessible(true);
            /** @var Compressor $compressor */
            $compressor = $property->getValue($chunkCache);

            $world->getServer()->getAsyncPool()->submitTask(
                new ChunkRequestTask(
                    $world,
                    $chunkX,
                    $chunkZ,
                    $chunk,
                    $caches[$chunkHash],
                    $compressor,
                    function() use ($world, $chunkCache, $chunkHash, $chunkX, $chunkZ) : void{
                        $world->getLogger()->error("Failed preparing chunk $chunkX $chunkZ, retrying");

                        $property = new ReflectionProperty(ChunkCache::class, "caches");
                        $property->setAccessible(true);
                        /** @var CompressBatchPromise[] $caches */
                        $caches = $property->getValue($chunkCache);
                        if(isset($caches[$chunkHash])){
                            $this->restartPendingRequest($chunkCache, $chunkX, $chunkZ);
                        }
                    }
                )
            );

            return $caches[$chunkHash];
        }finally{
            $world->timings->syncChunkSendPrepare->stopTiming();
        }
    }

    /**
     * Restarts an async request for an unresolved chunk.
     *
     * @throws \InvalidArgumentException
     */
    private function restartPendingRequest(ChunkCache $chunkCache, int $chunkX, int $chunkZ) : void{
        $chunkHash = World::chunkHash($chunkX, $chunkZ);

        $property = new ReflectionProperty(ChunkCache::class, "caches");
        $property->setAccessible(true);
        /** @var CompressBatchPromise[] $caches */
        $caches = $property->getValue($chunkCache);

        $existing = $caches[$chunkHash] ?? null;
        if($existing === null || $existing->hasResult()){
            throw new \InvalidArgumentException("Restart can only be applied to unresolved promises");
        }
        $existing->cancel();
        unset($caches[$chunkHash]);
        $property->setValue($chunkCache, $caches);

        $this->request($chunkCache, $chunkX, $chunkZ)->onResolve(...$existing->getResolveCallbacks());
    }
}