package com.athenastudios.athenacore.client;

import com.athenastudios.athenacore.AthenaCore;
import com.athenastudios.athenacore.net.JoinTokenMessage;
import com.athenastudios.athenacore.util.SessionFile;
import net.minecraft.client.entity.AbstractClientPlayer;
import net.minecraft.client.entity.EntityPlayerSP;
import net.minecraftforge.event.entity.EntityJoinWorldEvent;
import net.minecraftforge.fml.common.eventhandler.SubscribeEvent;
import net.minecraftforge.fml.common.network.FMLNetworkEvent;

/**
 * Client-side: sends the join token once on world join, applies custom skins to
 * each player, and resets state on disconnect.
 */
public class ClientEvents {
    private boolean tokenSent = false;

    @SubscribeEvent
    public void onEntityJoin(EntityJoinWorldEvent event) {
        if (!event.getWorld().isRemote) {
            return;
        }
        if (event.getEntity() instanceof EntityPlayerSP && !tokenSent) {
            tokenSent = true;
            String token = SessionFile.readToken();
            if (token != null && !token.isEmpty()) {
                AthenaCore.CHANNEL.sendToServer(new JoinTokenMessage(token));
            }
        }
        if (event.getEntity() instanceof AbstractClientPlayer) {
            SkinApplier.apply((AbstractClientPlayer) event.getEntity());
        }
    }

    @SubscribeEvent
    public void onDisconnect(FMLNetworkEvent.ClientDisconnectionFromServerEvent event) {
        tokenSent = false;
        SkinApplier.reset();
    }
}
