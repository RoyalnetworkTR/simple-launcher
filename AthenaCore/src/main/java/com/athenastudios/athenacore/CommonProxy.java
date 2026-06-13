package com.athenastudios.athenacore;

import com.athenastudios.athenacore.server.ServerEvents;
import net.minecraftforge.common.MinecraftForge;
import net.minecraftforge.fml.common.event.FMLInitializationEvent;
import net.minecraftforge.fml.common.event.FMLPreInitializationEvent;

public class CommonProxy {
    public void preInit(FMLPreInitializationEvent event) {
        // no-op
    }

    public void init(FMLInitializationEvent event) {
        // Login gate runs on the (integrated or dedicated) server side.
        MinecraftForge.EVENT_BUS.register(new ServerEvents());
    }
}
