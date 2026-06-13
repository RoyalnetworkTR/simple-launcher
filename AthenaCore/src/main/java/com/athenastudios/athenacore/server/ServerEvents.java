package com.athenastudios.athenacore.server;

import com.athenastudios.athenacore.AthenaConfig;
import net.minecraft.entity.player.EntityPlayerMP;
import net.minecraft.util.text.TextComponentString;
import net.minecraftforge.fml.common.FMLCommonHandler;
import net.minecraftforge.fml.common.eventhandler.SubscribeEvent;
import net.minecraftforge.fml.common.gameevent.PlayerEvent;
import net.minecraftforge.fml.common.gameevent.TickEvent;

import java.util.Iterator;
import java.util.Map;
import java.util.Set;
import java.util.UUID;
import java.util.concurrent.ConcurrentHashMap;

/**
 * Login gate. When a server key is configured, every player must present a valid
 * join token (sent by AthenaCore's client) within {@code graceSeconds} or they
 * are kicked. This blocks vanilla / launcher-bypassing clients on an offline server.
 */
public class ServerEvents {
    private static final Set<UUID> VERIFIED = ConcurrentHashMap.newKeySet();
    private static final Map<UUID, Long> PENDING = new ConcurrentHashMap<>(); // uuid -> deadline (epoch ms)

    public static void markVerified(UUID id) {
        VERIFIED.add(id);
        PENDING.remove(id);
    }

    @SubscribeEvent
    public void onLogin(PlayerEvent.PlayerLoggedInEvent event) {
        if (!(event.player instanceof EntityPlayerMP)) {
            return;
        }
        // No enforcement without a configured server key (integrated server / not set up yet).
        if (AthenaConfig.serverKey == null || AthenaConfig.serverKey.isEmpty()) {
            return;
        }
        UUID id = event.player.getUniqueID();
        VERIFIED.remove(id);
        PENDING.put(id, System.currentTimeMillis() + AthenaConfig.graceSeconds * 1000L);
    }

    @SubscribeEvent
    public void onLogout(PlayerEvent.PlayerLoggedOutEvent event) {
        UUID id = event.player.getUniqueID();
        VERIFIED.remove(id);
        PENDING.remove(id);
    }

    @SubscribeEvent
    public void onServerTick(TickEvent.ServerTickEvent event) {
        if (event.phase != TickEvent.Phase.END || PENDING.isEmpty()) {
            return;
        }
        long now = System.currentTimeMillis();
        Iterator<Map.Entry<UUID, Long>> it = PENDING.entrySet().iterator();
        while (it.hasNext()) {
            Map.Entry<UUID, Long> e = it.next();
            if (VERIFIED.contains(e.getKey())) {
                it.remove();
                continue;
            }
            if (now >= e.getValue()) {
                it.remove();
                EntityPlayerMP p = FMLCommonHandler.instance().getMinecraftServerInstance()
                        .getPlayerList().getPlayerByUUID(e.getKey());
                if (p != null && p.connection != null) {
                    p.connection.disconnect(new TextComponentString(
                            "AthenaCore doğrulaması gerekli.\nLütfen Athena Launcher ile giriş yapın."));
                }
            }
        }
    }
}
