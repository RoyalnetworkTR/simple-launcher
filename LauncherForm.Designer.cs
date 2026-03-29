namespace OfflineMinecraftLauncher
{
    partial class LauncherForm
    {
        private System.ComponentModel.IContainer components = null;

        protected override void Dispose(bool disposing)
        {
            if (disposing && (components != null))
            {
                components.Dispose();
            }
            base.Dispose(disposing);
        }

        #region Windows Form Designer generated code

        private void InitializeComponent()
        {
            System.ComponentModel.ComponentResourceManager resources = new System.ComponentModel.ComponentResourceManager(typeof(LauncherForm));
            this.pbFiles = new System.Windows.Forms.ProgressBar();
            this.pbProgress = new System.Windows.Forms.ProgressBar();
            this.lbProgress = new System.Windows.Forms.Label();
            this.btnStart = new System.Windows.Forms.Button();
            this.usernameInput = new System.Windows.Forms.TextBox();
            this.label2 = new System.Windows.Forms.Label();
            this.lblStatus = new System.Windows.Forms.Label();
            this.btnSettings = new System.Windows.Forms.Button();
            this.SuspendLayout();
            // 
            // pbFiles
            // 
            this.pbFiles.Anchor = ((System.Windows.Forms.AnchorStyles)(((System.Windows.Forms.AnchorStyles.Top | System.Windows.Forms.AnchorStyles.Left) 
            | System.Windows.Forms.AnchorStyles.Right)));
            this.pbFiles.Location = new System.Drawing.Point(12, 148);
            this.pbFiles.Name = "pbFiles";
            this.pbFiles.Size = new System.Drawing.Size(380, 20);
            this.pbFiles.TabIndex = 3;
            // 
            // pbProgress
            // 
            this.pbProgress.Anchor = ((System.Windows.Forms.AnchorStyles)(((System.Windows.Forms.AnchorStyles.Top | System.Windows.Forms.AnchorStyles.Left) 
            | System.Windows.Forms.AnchorStyles.Right)));
            this.pbProgress.Location = new System.Drawing.Point(12, 177);
            this.pbProgress.Name = "pbProgress";
            this.pbProgress.Size = new System.Drawing.Size(380, 20);
            this.pbProgress.TabIndex = 4;
            // 
            // lbProgress
            // 
            this.lbProgress.Location = new System.Drawing.Point(12, 130);
            this.lbProgress.Name = "lbProgress";
            this.lbProgress.Size = new System.Drawing.Size(380, 15);
            this.lbProgress.TabIndex = 5;
            // 
            // btnStart
            // 
            this.btnStart.Anchor = ((System.Windows.Forms.AnchorStyles)(((System.Windows.Forms.AnchorStyles.Top | System.Windows.Forms.AnchorStyles.Left) 
            | System.Windows.Forms.AnchorStyles.Right)));
            this.btnStart.Font = new System.Drawing.Font("Segoe UI", 16F, System.Drawing.FontStyle.Bold);
            this.btnStart.Location = new System.Drawing.Point(12, 60);
            this.btnStart.Margin = new System.Windows.Forms.Padding(0);
            this.btnStart.Name = "btnStart";
            this.btnStart.Size = new System.Drawing.Size(380, 50);
            this.btnStart.TabIndex = 7;
            this.btnStart.Text = "OYNA";
            this.btnStart.UseVisualStyleBackColor = true;
            this.btnStart.Click += new System.EventHandler(this.btnStart_Click);
            // 
            // usernameInput
            // 
            this.usernameInput.Anchor = ((System.Windows.Forms.AnchorStyles)(((System.Windows.Forms.AnchorStyles.Top | System.Windows.Forms.AnchorStyles.Left) 
            | System.Windows.Forms.AnchorStyles.Right)));
            this.usernameInput.Location = new System.Drawing.Point(12, 27);
            this.usernameInput.Name = "usernameInput";
            this.usernameInput.Size = new System.Drawing.Size(293, 23);
            this.usernameInput.TabIndex = 9;
            this.usernameInput.TextChanged += new System.EventHandler(this.usernameInput_TextChanged);
            // 
            // label2
            // 
            this.label2.AutoSize = true;
            this.label2.Location = new System.Drawing.Point(12, 9);
            this.label2.Name = "label2";
            this.label2.Size = new System.Drawing.Size(63, 15);
            this.label2.TabIndex = 8;
            this.label2.Text = "Username:";
            // 
            // lblStatus
            // 
            this.lblStatus.AutoSize = true;
            this.lblStatus.Location = new System.Drawing.Point(120, 9);
            this.lblStatus.Name = "lblStatus";
            this.lblStatus.Size = new System.Drawing.Size(100, 15);
            this.lblStatus.TabIndex = 10;
            this.lblStatus.Text = "Sunucu Durumu: ?";
            // 
            // btnSettings
            // 
            this.btnSettings.Anchor = ((System.Windows.Forms.AnchorStyles)((System.Windows.Forms.AnchorStyles.Top | System.Windows.Forms.AnchorStyles.Right)));
            this.btnSettings.Location = new System.Drawing.Point(311, 27);
            this.btnSettings.Name = "btnSettings";
            this.btnSettings.Size = new System.Drawing.Size(81, 23);
            this.btnSettings.TabIndex = 11;
            this.btnSettings.Text = "Ayarlar";
            this.btnSettings.UseVisualStyleBackColor = true;
            this.btnSettings.Click += new System.EventHandler(this.btnSettings_Click);
            // 
            // LauncherForm
            // 
            this.AutoScaleDimensions = new System.Drawing.SizeF(7F, 15F);
            this.AutoScaleMode = System.Windows.Forms.AutoScaleMode.Font;
            this.ClientSize = new System.Drawing.Size(404, 211);
            this.Controls.Add(this.btnSettings);
            this.Controls.Add(this.lblStatus);
            this.Controls.Add(this.usernameInput);
            this.Controls.Add(this.label2);
            this.Controls.Add(this.btnStart);
            this.Controls.Add(this.lbProgress);
            this.Controls.Add(this.pbProgress);
            this.Controls.Add(this.pbFiles);
            this.Icon = ((System.Drawing.Icon)(resources.GetObject("$this.Icon")));
            this.MaximumSize = new System.Drawing.Size(620, 250);
            this.MinimumSize = new System.Drawing.Size(420, 250);
            this.Name = "LauncherForm";
            this.StartPosition = System.Windows.Forms.FormStartPosition.CenterScreen;
            this.Text = "Royalnetwork Launcher";
            this.Load += new System.EventHandler(this.LauncherForm_Load);
            this.ResumeLayout(false);
            this.PerformLayout();

        }

        #endregion

        private System.Windows.Forms.ProgressBar pbFiles;
        private System.Windows.Forms.ProgressBar pbProgress;
        private System.Windows.Forms.Label lbProgress;
        private System.Windows.Forms.Button btnStart;
        private System.Windows.Forms.TextBox usernameInput;
        private System.Windows.Forms.Label label2;
        private System.Windows.Forms.Label lblStatus;
        private System.Windows.Forms.Button btnSettings;
    }
}
