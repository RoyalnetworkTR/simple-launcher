package com.athenastudios.launcher

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.viewModels
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import com.athenastudios.launcher.ui.AppRoot
import com.athenastudios.launcher.ui.theme.AthenaTheme

class MainActivity : ComponentActivity() {
    private val vm: AppViewModel by viewModels()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContent {
            AthenaTheme {
                val state by vm.state.collectAsState()
                AppRoot(state, vm)
            }
        }
    }
}
