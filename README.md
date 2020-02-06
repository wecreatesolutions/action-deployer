# Deployer action

This action handles deployment via deployer

```
  - name: Deploy
    uses: wearebuilders/action-deployer@master
    env:
      SSH_PRIVATE_KEY: ${{ secrets.PRIVATE_KEY }}
      GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
      SLACK_WEBHOOK_TOKEN: ${{ secrets.SLACK_WEBHOOK_TOKEN }}
    with:
      args: --file=./deploy.php -v deploy
```
